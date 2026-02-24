<?php
/**
 * Shortcode: [service_faq_page]
 *
 * Renders a full Service FAQ page by gathering FAQ custom fields from all
 * published Service posts. Each service gets an H3 heading followed by a
 * Bootstrap 5 accordion of its FAQs. Duplicate questions are automatically
 * removed (first occurrence wins).
 *
 * FAQPage JSON-LD schema is handled by the dedicated provider at
 * inc/schema/providers/service-faq-page.php (hooks into wp_head via the
 * schema graph — NOT inside this shortcode).
 *
 * Attributes:
 *   title          - Page heading (H1). Default: "Service FAQs". Set title="" to hide.
 *   btn_bg         - Accordion button background color (CSS var override).
 *   btn_color      - Accordion button text color (CSS var override).
 *   heading_color  - H3 service name color (CSS var override).
 *   orderby        - WP_Query orderby. Default: "menu_order".
 *   order          - ASC or DESC. Default: "ASC".
 *   show_empty     - "1" to show services with no FAQs. Default: "1".
 *   empty_message  - Message when a service has no FAQs. Default: "No FAQs available for this service."
 *
 * @since 4.15.3
 * @updated 4.15.5 — Schema moved to dedicated provider; shortcode is HTML-only.
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------------------------
 * Helper: collect + normalize FAQs from a single post (MYLS → ACF fallback)
 * Returns array of [ 'q' => string, 'a' => html_string ]
 * ------------------------------------------------------------------------- */
if ( ! function_exists( 'myls_collect_post_faqs' ) ) {
	function myls_collect_post_faqs( int $post_id ) : array {
		$faqs = [];

		// 1) Native MYLS custom fields.
		if ( function_exists( 'myls_get_faq_items_meta' ) ) {
			$items = myls_get_faq_items_meta( $post_id );
			if ( is_array( $items ) ) {
				foreach ( $items as $row ) {
					if ( ! is_array( $row ) ) continue;
					$q = trim( sanitize_text_field( (string) ( $row['q'] ?? '' ) ) );
					$a = trim( wp_kses_post( (string) ( $row['a'] ?? '' ) ) );
					if ( $q !== '' && $a !== '' ) {
						$faqs[] = [ 'q' => $q, 'a' => $a ];
					}
				}
			}
		}

		// 2) Legacy ACF repeater fallback.
		if ( empty( $faqs ) && function_exists( 'have_rows' ) && function_exists( 'get_sub_field' ) ) {
			if ( have_rows( 'faq_items', $post_id ) ) {
				while ( have_rows( 'faq_items', $post_id ) ) {
					the_row();
					$q = trim( sanitize_text_field( (string) get_sub_field( 'question' ) ) );
					$a = trim( wp_kses_post( (string) get_sub_field( 'answer' ) ) );
					if ( $q !== '' && $a !== '' ) {
						$faqs[] = [ 'q' => $q, 'a' => $a ];
					}
				}
			}
		}

		return $faqs;
	}
}

/* -------------------------------------------------------------------------
 * Helper: deduplicate FAQ items by normalized question text.
 * First occurrence wins; returns [ 'q' => ..., 'a' => ... ] array.
 * ------------------------------------------------------------------------- */
if ( ! function_exists( 'myls_dedupe_faqs' ) ) {
	function myls_dedupe_faqs( array $faqs ) : array {
		$seen = [];
		$out  = [];
		foreach ( $faqs as $row ) {
			$key = mb_strtolower( trim( $row['q'] ?? '' ) );
			if ( $key === '' || isset( $seen[ $key ] ) ) continue;
			$seen[ $key ] = true;
			$out[] = $row;
		}
		return $out;
	}
}

/* -------------------------------------------------------------------------
 * Shortcode handler
 * ------------------------------------------------------------------------- */
if ( ! function_exists( 'myls_service_faq_page_shortcode' ) ) {

	function myls_service_faq_page_shortcode( $atts ) {

		// ── Bootstrap 5 JS ──
		$handle = 'myls-bootstrap5-bundle';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script(
				$handle,
				'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
				[],
				'5.3.3',
				true
			);
		}
		wp_enqueue_script( $handle );

		// ── Accordion CSS ──
		$css_handle = 'myls-accordion-css';
		if ( ! wp_style_is( $css_handle, 'registered' ) ) {
			wp_register_style(
				$css_handle,
				plugins_url( 'assets/css/myls-accordion.min.css', MYLS_MAIN_FILE ),
				[],
				MYLS_VERSION
			);
		}
		wp_enqueue_style( $css_handle );

		// Force-init Bootstrap collapse.
		add_action( 'wp_footer', function () use ( $handle ) {
			static $printed = false;
			if ( $printed ) return;
			$printed = true;
			$js = <<<JS
document.addEventListener('DOMContentLoaded', function () {
  if (!window.bootstrap || !bootstrap.Collapse) return;
  document.querySelectorAll('.accordion .accordion-collapse').forEach(function(el){
    try { bootstrap.Collapse.getOrCreateInstance(el, { toggle: false }); } catch(e){}
  });
});
JS;
			wp_add_inline_script( $handle, $js );
		}, 100 );

		// ── Shortcode attributes ──
		// Default title pulls from the current post/page title.
		$default_title = '';
		$current_post  = get_queried_object();
		if ( $current_post instanceof WP_Post ) {
			$default_title = get_the_title( $current_post->ID );
		}
		if ( $default_title === '' ) {
			$default_title = 'Service FAQs';
		}

		$atts = shortcode_atts(
			[
				'title'         => $default_title,
				'btn_bg'        => '',
				'btn_color'     => '',
				'heading_color' => '',
				'orderby'       => 'menu_order',
				'order'         => 'ASC',
				'show_empty'    => '1',
				'empty_message' => 'No FAQs available for this service.',
			],
			$atts,
			'service_faq_page'
		);

		// ── Query all published services ──
		$services = get_posts([
			'post_type'      => 'service',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => sanitize_key( $atts['orderby'] ),
			'order'          => strtoupper( $atts['order'] ) === 'DESC' ? 'DESC' : 'ASC',
		]);

		if ( empty( $services ) ) {
			return '<p><em>No services found.</em></p>';
		}

		// ── Collect all FAQs per service + global dedup tracker ──
		$global_seen   = [];
		$service_data  = [];

		foreach ( $services as $service ) {
			$post_id   = (int) $service->ID;
			$raw_faqs  = myls_collect_post_faqs( $post_id );
			$svc_faqs  = [];

			foreach ( $raw_faqs as $row ) {
				$key = mb_strtolower( trim( $row['q'] ) );
				if ( $key === '' || isset( $global_seen[ $key ] ) ) continue;
				$global_seen[ $key ] = true;
				$svc_faqs[]          = $row;
			}

			$service_data[] = [
				'title' => get_the_title( $post_id ),
				'faqs'  => $svc_faqs,
			];
		}

		// ── Per-instance CSS variables ──
		$btn_bg        = trim( (string) $atts['btn_bg'] );
		$btn_color     = trim( (string) $atts['btn_color'] );
		$heading_color = trim( (string) $atts['heading_color'] );

		$css_vars = [];
		if ( $btn_bg !== '' )        $css_vars[] = '--myls-faq-btn-bg:'        . esc_attr( $btn_bg )        . ';';
		if ( $btn_color !== '' )     $css_vars[] = '--myls-faq-btn-color:'     . esc_attr( $btn_color )     . ';';
		if ( $heading_color !== '' ) $css_vars[] = '--myls-faq-heading-color:' . esc_attr( $heading_color ) . ';';

		$vars_attr   = $css_vars ? ' style="' . implode( ' ', $css_vars ) . '"' : '';
		$show_empty  = ( $atts['show_empty'] === '1' );
		$empty_msg   = esc_html( $atts['empty_message'] );

		// ── Render HTML ──
		ob_start();

		$title = trim( (string) $atts['title'] );
		if ( $title !== '' ) {
			echo '<h1 class="myls-service-faq-title text-center"' . $vars_attr . '>' . esc_html( $title ) . '</h1>' . "\n";
		}

		echo '<div class="myls-service-faq-page"' . $vars_attr . '>' . "\n";

		foreach ( $service_data as $svc ) {

			if ( empty( $svc['faqs'] ) && ! $show_empty ) continue;

			// ── H3: Service Name ──
			echo '<h3 class="myls-service-faq-heading">' . esc_html( $svc['title'] ) . '</h3>' . "\n";

			if ( empty( $svc['faqs'] ) ) {
				echo '<p class="myls-service-faq-empty"><em>' . $empty_msg . '</em></p>' . "\n";
				continue;
			}

			// ── Bootstrap Accordion ──
			$accordion_id = 'svcFaqAcc_' . sanitize_title( $svc['title'] ) . '_' . wp_unique_id();
			?>
			<div class="accordion ssseo-accordion myls-faq-accordion" id="<?php echo esc_attr( $accordion_id ); ?>">
				<?php
				$index = 0;
				foreach ( $svc['faqs'] as $row ) :
					$heading_id  = esc_attr( "{$accordion_id}_h_{$index}" );
					$collapse_id = esc_attr( "{$accordion_id}_c_{$index}" );
					?>
					<div class="ssseo-accordion-item accordion-item">
						<h2 class="ssseo-accordion-header accordion-header" id="<?php echo $heading_id; ?>">
							<button
								class="accordion-button collapsed"
								type="button"
								data-bs-toggle="collapse"
								data-bs-target="#<?php echo $collapse_id; ?>"
								aria-expanded="false"
								aria-controls="<?php echo $collapse_id; ?>"
							>
								<?php echo esc_html( $row['q'] ); ?>
							</button>
						</h2>
						<div
							id="<?php echo $collapse_id; ?>"
							class="accordion-collapse collapse"
							aria-labelledby="<?php echo $heading_id; ?>"
							data-bs-parent="#<?php echo esc_attr( $accordion_id ); ?>"
						>
							<div class="accordion-body">
								<?php echo $row['a']; ?>
							</div>
						</div>
					</div>
					<?php
					$index++;
				endforeach;
				?>
			</div>
			<?php
		}

		echo '</div>' . "\n";

		return ob_get_clean();
	}
}

add_shortcode( 'service_faq_page', 'myls_service_faq_page_shortcode' );
