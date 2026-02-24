<?php
/**
 * Shortcode: [faq_schema_accordion]
 *
 * Bootstrap 5 accordion. Requires bootstrap.bundle.min.js on the page.
 *
 * Attributes:
 * - heading="Frequently Asked Questions"
 *     - heading omitted  => defaults to "Frequently Asked Questions"
 *     - heading=""       => hides heading (only if explicitly provided)
 *     - heading="Text"   => prints centered H2 with that text (plain text only)
 *
 * - heading_sc=""  (MOST RELIABLE)
 *     - Allows a shortcode-driven heading without nesting [] inside the heading attribute.
 *     - Examples:
 *         heading_sc="page_title"
 *         heading_sc='page_title suffix=" FAQs"'
 *         heading_sc='city_state'
 *
 * - btn_bg="#005353"        (optional, per-instance via CSS var)
 * - btn_color="#ffffff"     (optional, per-instance via CSS var)
 * - heading_color=""        (optional, per-instance via CSS var; blank = inherit)
 *
 * Examples:
 * [faq_schema_accordion heading_sc="page_title"]
 * [faq_schema_accordion heading_sc='page_title suffix=" FAQs"' btn_bg="#172751" btn_color="#ffffff" heading_color="#172751"]
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists( 'faq_schema_accordion_shortcode' ) ) {

	function faq_schema_accordion_shortcode( $atts ) {

		// ------------------------------------------------------------
		// Ensure Bootstrap 5 JS is present (collapse needs JS, not CSS).
		// If your theme/plugin already enqueues Bootstrap 5 bundle, this
		// will simply no-op (same handle) or you can remove this block.
		// ------------------------------------------------------------
		$handle = 'myls-bootstrap5-bundle';

		// Register only if not already registered.
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script(
				$handle,
				'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
				[],
				'5.3.3',
				true
			);
		}

		// Enqueue only when shortcode renders.
		wp_enqueue_script( $handle );

		// Optional: force-init collapse for this accordion after render.
		// (Bootstrap’s data API *should* handle it, but this helps if content
		// is injected late or another script interferes.)
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

		/**
		 * MYLS – ACF removal path:
		 * - Prefer native MYLS FAQ meta stored in _myls_faq_items.
		 * - Fall back to legacy ACF repeater faq_items (question/answer) if present.
		 */

		// Detect whether heading was explicitly provided
		$heading_was_provided = is_array( $atts ) && array_key_exists( 'heading', $atts );

		// Apply defaults (including per-shortcode style vars)
		$atts = shortcode_atts(
			[
				'heading'       => 'Frequently Asked Questions',

				// ✅ Reliable way to generate a heading via shortcode without nesting [] in attributes
				// Examples: heading_sc="page_title" OR heading_sc='page_title suffix=" FAQs"'
				'heading_sc'    => '',

				// Optional per-instance colors (CSS must consume these vars)
				'btn_bg'        => '',
				'btn_color'     => '',
				'heading_color' => '',
			],
			$atts,
			'faq_schema_accordion'
		);

		// ------------------------------------------------------------
		// Heading rules:
		// - heading omitted  => defaults to "Frequently Asked Questions"
		// - heading=""       => hides heading (only if explicitly provided)
		// - heading_sc set   => executes that shortcode and uses its output as the heading
		// ------------------------------------------------------------
		$heading_text  = '';
		$print_heading = true;

		$heading_attr_raw = (string) ( $atts['heading'] ?? '' );
		$heading_sc_raw   = trim( (string) ( $atts['heading_sc'] ?? '' ) );

		// If heading explicitly set to blank AND no heading_sc, hide heading
		if ( $heading_was_provided && trim( $heading_attr_raw ) === '' && $heading_sc_raw === '' ) {
			$print_heading = false;
		} else {

			// Prefer heading_sc when provided (avoids WP nested-shortcode-in-attribute parsing issues)
			if ( $heading_sc_raw !== '' ) {

				// Build shortcode: [heading_sc_raw]
				// heading_sc_raw may include attributes (e.g. page_title suffix=" FAQs")
				$rendered = do_shortcode( '[' . $heading_sc_raw . ']' );

				// Keep heading plain text (safe + predictable for <h2>)
				$heading_text = trim( wp_strip_all_tags( (string) $rendered ) );

				// If shortcode renders empty, fall back to heading attribute (or default)
				if ( $heading_text === '' ) {
					$heading_text = trim( wp_strip_all_tags( $heading_attr_raw ) );
				}

			} else {

				// Normal path: allow nested shortcodes in heading="..."
				$heading_rendered = do_shortcode( $heading_attr_raw );
				$heading_text     = trim( wp_strip_all_tags( (string) $heading_rendered ) );
			}
		}

		// Determine current post ID
		$post_id = 0;
		if ( isset( $GLOBALS['post'] ) && is_object( $GLOBALS['post'] ) && ! empty( $GLOBALS['post']->ID ) ) {
			$post_id = (int) $GLOBALS['post']->ID;
		}
		if ( ! $post_id ) {
			$post_id = (int) get_the_ID();
		}
		if ( ! $post_id ) {
			return '<p><em>Unable to determine post ID.</em></p>';
		}

		// Collect FAQs (MYLS first, ACF fallback)
		$faqs = [];

		// 1) Native MYLS custom fields
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

		// 2) Legacy ACF repeater fallback
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

		// No FAQs => no output
		if ( empty( $faqs ) ) return '';

		// Unique accordion instance ID (also scopes per-instance CSS vars)
		$accordion_id = 'faqAccordion_' . wp_generate_uuid4();

		// ------------------------------------------------------------
		// Per-instance CSS variables (works with your CSS vars approach)
		// ------------------------------------------------------------
		$btn_bg        = trim( (string) $atts['btn_bg'] );
		$btn_color     = trim( (string) $atts['btn_color'] );
		$heading_color = trim( (string) $atts['heading_color'] );

		$css_vars = [];

		if ( $btn_bg !== '' ) {
			$css_vars[] = '--myls-faq-btn-bg:' . esc_attr( $btn_bg ) . ';';
		}
		if ( $btn_color !== '' ) {
			$css_vars[] = '--myls-faq-btn-color:' . esc_attr( $btn_color ) . ';';
		}
		if ( $heading_color !== '' ) {
			$css_vars[] = '--myls-faq-heading-color:' . esc_attr( $heading_color ) . ';';
		}

		$vars_attr = $css_vars ? ' style="' . implode( ' ', $css_vars ) . '"' : '';

		ob_start();
		?>

		<?php if ( $print_heading && $heading_text !== '' ) : ?>
			<h2 class="text-center myls-faq-heading"<?php echo $vars_attr; ?>>
				<?php echo esc_html( $heading_text ); ?>
			</h2>
		<?php endif; ?>

		<div class="accordion ssseo-accordion myls-faq-accordion" id="<?php echo esc_attr( $accordion_id ); ?>"<?php echo $vars_attr; ?>>
			<?php
			$index = 0;

			foreach ( $faqs as $row ) {

				$question = $row['q'];
				$answer   = $row['a'];

				$anchor_id   = 'faq-' . ( $index + 1 );
				$heading_id  = "{$accordion_id}_heading_{$index}";
				$collapse_id = "{$accordion_id}_collapse_{$index}";
				?>
				<div class="ssseo-accordion-item accordion-item" id="<?php echo esc_attr( $anchor_id ); ?>">
					<h2 class="ssseo-accordion-header accordion-header" id="<?php echo esc_attr( $heading_id ); ?>">
						<button
							class="accordion-button collapsed"
							type="button"
							data-bs-toggle="collapse"
							data-bs-target="#<?php echo esc_attr( $collapse_id ); ?>"
							aria-expanded="false"
							aria-controls="<?php echo esc_attr( $collapse_id ); ?>"
						>
							<?php echo esc_html( $question ); ?>
						</button>
					</h2>

					<div
						id="<?php echo esc_attr( $collapse_id ); ?>"
						class="accordion-collapse collapse"
						aria-labelledby="<?php echo esc_attr( $heading_id ); ?>"
						data-bs-parent="#<?php echo esc_attr( $accordion_id ); ?>"
					>
						<div class="accordion-body">
							<?php echo $answer; // already wp_kses_post()'d above ?>
						</div>
					</div>
				</div>
				<?php
				$index++;
			}
			?>
		</div>

		<?php
		return ob_get_clean();
	}
}

add_shortcode( 'faq_schema_accordion', 'faq_schema_accordion_shortcode' );
