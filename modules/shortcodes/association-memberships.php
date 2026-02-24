<?php
/**
 * Shortcode: [association_memberships]
 *
 * Renders association memberships as a responsive logo grid card layout.
 * Data is pulled from the `myls_org_memberships` option (managed in
 * Schema → Organization subtab).
 *
 * Attributes:
 *   title      - Page heading (H1). Defaults to current post title. Set title="" to hide.
 *   columns    - Grid columns: 2, 3, or 4. Default: 3.
 *   show_desc  - "1" to show descriptions. Default: "1".
 *   show_since - "1" to show "Member since" badge. Default: "1".
 *   link_text  - Text for profile link button. Default: "View Our Profile".
 *   card_bg    - Card background color override.
 *   card_border- Card border color override.
 *   orderby    - Sort order: "manual" (admin drag order, default), "name", or "since".
 *   order      - Sort direction: "asc" (default) or "desc". Only applies when orderby is name/since.
 *
 * @since 4.15.8
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists( 'myls_association_memberships_shortcode' ) ) {

	function myls_association_memberships_shortcode( $atts ) {

		// Default title from current post.
		$default_title = '';
		$current_post  = get_queried_object();
		if ( $current_post instanceof WP_Post ) {
			$default_title = get_the_title( $current_post->ID );
		}
		if ( $default_title === '' ) {
			$default_title = 'Our Memberships & Associations';
		}

		$atts = shortcode_atts(
			[
				'title'       => $default_title,
				'columns'     => '3',
				'show_desc'   => '1',
				'show_since'  => '1',
				'link_text'   => 'View Our Profile',
				'card_bg'     => '',
				'card_border' => '',
				'orderby'     => 'manual',
				'order'       => 'asc',
			],
			$atts,
			'association_memberships'
		);

		$memberships = (array) get_option( 'myls_org_memberships', [] );
		// Filter to only entries with a name.
		$memberships = array_values( array_filter( $memberships, function( $m ) {
			return is_array( $m ) && ! empty( $m['name'] );
		}) );

		if ( empty( $memberships ) ) {
			return '<p><em>No memberships found.</em></p>';
		}

		// Ordering: 'manual' (default) respects admin drag-and-drop order.
		$orderby = strtolower( trim( $atts['orderby'] ) );
		$order   = strtolower( trim( $atts['order'] ) ) === 'desc' ? 'desc' : 'asc';

		if ( $orderby === 'name' ) {
			usort( $memberships, function( $a, $b ) use ( $order ) {
				$cmp = strcasecmp( $a['name'] ?? '', $b['name'] ?? '' );
				return $order === 'desc' ? -$cmp : $cmp;
			});
		} elseif ( $orderby === 'since' ) {
			usort( $memberships, function( $a, $b ) use ( $order ) {
				$cmp = strcmp( $a['since'] ?? '', $b['since'] ?? '' );
				return $order === 'desc' ? -$cmp : $cmp;
			});
		}
		// 'manual' = no re-sort, use array order as-is from admin

		$cols       = in_array( (int) $atts['columns'], [2,3,4], true ) ? (int) $atts['columns'] : 3;
		$show_desc  = ( $atts['show_desc'] === '1' );
		$show_since = ( $atts['show_since'] === '1' );
		$link_text  = esc_html( $atts['link_text'] );
		$card_bg    = trim( $atts['card_bg'] );
		$card_border= trim( $atts['card_border'] );

		$card_style = '';
		if ( $card_bg !== '' )     $card_style .= 'background:' . esc_attr($card_bg) . ';';
		if ( $card_border !== '' ) $card_style .= 'border-color:' . esc_attr($card_border) . ';';

		// CSS grid mapping.
		$grid_cols = [2 => 'repeat(2,1fr)', 3 => 'repeat(3,1fr)', 4 => 'repeat(4,1fr)'];

		ob_start();
		?>
		<style>
		.myls-memberships-grid {
			display: grid;
			grid-template-columns: <?php echo $grid_cols[$cols]; ?>;
			gap: 24px;
			margin: 24px 0;
		}
		@media (max-width: 768px) {
			.myls-memberships-grid { grid-template-columns: 1fr; }
		}
		@media (min-width: 769px) and (max-width: 1024px) {
			.myls-memberships-grid { grid-template-columns: repeat(2, 1fr); }
		}
		.myls-membership-card {
			background: #fff;
			border: 1px solid #e0e0e0;
			border-radius: 12px;
			padding: 24px;
			text-align: center;
			transition: box-shadow 0.2s ease, transform 0.2s ease;
			display: flex;
			flex-direction: column;
			align-items: center;
		}
		.myls-membership-card:hover {
			box-shadow: 0 4px 16px rgba(0,0,0,0.1);
			transform: translateY(-2px);
		}
		.myls-membership-card__logo {
			max-width: 160px;
			max-height: 100px;
			object-fit: contain;
			margin-bottom: 16px;
		}
		.myls-membership-card__name {
			font-size: 1.15rem;
			font-weight: 700;
			margin: 0 0 8px;
			color: #222;
		}
		.myls-membership-card__name a {
			color: inherit;
			text-decoration: none;
		}
		.myls-membership-card__name a:hover {
			text-decoration: underline;
		}
		.myls-membership-card__since {
			display: inline-block;
			background: #e8f5e9;
			color: #2e7d32;
			font-size: .78rem;
			font-weight: 600;
			padding: 3px 10px;
			border-radius: 20px;
			margin-bottom: 10px;
		}
		.myls-membership-card__desc {
			font-size: .9rem;
			color: #555;
			margin: 0 0 14px;
			flex-grow: 1;
		}
		.myls-membership-card__link {
			display: inline-block;
			font-size: .85rem;
			font-weight: 600;
			color: #0d6efd;
			text-decoration: none;
			padding: 6px 16px;
			border: 1px solid #0d6efd;
			border-radius: 20px;
			transition: background 0.2s, color 0.2s;
		}
		.myls-membership-card__link:hover {
			background: #0d6efd;
			color: #fff;
		}
		</style>
		<?php

		$title = trim( (string) $atts['title'] );
		if ( $title !== '' ) {
			echo '<h1 class="myls-memberships-title" style="text-align:center;">' . esc_html( $title ) . '</h1>' . "\n";
		}

		echo '<div class="myls-memberships-grid">' . "\n";

		foreach ( $memberships as $m ) {
			$m = wp_parse_args( (array) $m, ['name'=>'','url'=>'','profile_url'=>'','logo_url'=>'','description'=>'','since'=>''] );

			$style_attr = $card_style ? ' style="' . esc_attr($card_style) . '"' : '';
			?>
			<div class="myls-membership-card"<?php echo $style_attr; ?>>
				<?php if ( ! empty( $m['logo_url'] ) ) : ?>
					<?php if ( ! empty( $m['url'] ) ) : ?>
						<a href="<?php echo esc_url( $m['url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<img class="myls-membership-card__logo"
								src="<?php echo esc_url( $m['logo_url'] ); ?>"
								alt="<?php echo esc_attr( $m['name'] ); ?> logo"
								loading="lazy">
						</a>
					<?php else : ?>
						<img class="myls-membership-card__logo"
							src="<?php echo esc_url( $m['logo_url'] ); ?>"
							alt="<?php echo esc_attr( $m['name'] ); ?> logo"
							loading="lazy">
					<?php endif; ?>
				<?php endif; ?>

				<h3 class="myls-membership-card__name">
					<?php if ( ! empty( $m['url'] ) ) : ?>
						<a href="<?php echo esc_url( $m['url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $m['name'] ); ?>
						</a>
					<?php else : ?>
						<?php echo esc_html( $m['name'] ); ?>
					<?php endif; ?>
				</h3>

				<?php if ( $show_since && ! empty( $m['since'] ) ) : ?>
					<span class="myls-membership-card__since">Member since <?php echo esc_html( $m['since'] ); ?></span>
				<?php endif; ?>

				<?php if ( $show_desc && ! empty( $m['description'] ) ) : ?>
					<p class="myls-membership-card__desc"><?php echo esc_html( $m['description'] ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $m['profile_url'] ) && $link_text !== '' ) : ?>
					<a class="myls-membership-card__link" href="<?php echo esc_url( $m['profile_url'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo $link_text; ?> →
					</a>
				<?php endif; ?>
			</div>
			<?php
		}

		echo '</div>' . "\n";

		return ob_get_clean();
	}
}

add_shortcode( 'association_memberships', 'myls_association_memberships_shortcode' );
