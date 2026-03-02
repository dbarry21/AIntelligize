<?php
/**
 * AI Deep Analysis — PDF Report Builder
 * File: inc/pdf/ai-deep-report.php
 *
 * Generates a professional multi-page PDF report from AI Deep Analysis results.
 * Uses MYLS_PDF (inc/lib/myls-pdf.php) — no external dependencies.
 *
 * Called by: AJAX action wp_ajax_myls_ca_deep_pdf_v1
 *
 * @since 7.7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once MYLS_PATH . 'inc/lib/myls-pdf.php';

class MYLS_AI_Deep_Report {

	/* ── Brand colours ──────────────────────────────────────────────────── */
	const C_DARK     = [ 31,  35,  39  ];   // near-black header
	const C_PURPLE   = [ 111, 66,  193 ];   // AI purple accent
	const C_BLUE     = [ 34,  113, 177 ];   // link blue
	const C_GREEN    = [  0,  112, 23  ];   // success
	const C_AMBER    = [ 153, 104,   0 ];   // warning
	const C_RED      = [ 214, 54,  56  ];   // danger/error
	const C_LIGHT_BG = [ 248, 249, 250 ];   // light section bg
	const C_PURPLE_BG= [ 245, 240, 255 ];   // purple-tinted bg
	const C_WHITE    = [ 255, 255, 255 ];
	const C_TEXT     = [  29,  35,  39  ];  // body text
	const C_MUTED    = [ 120, 124, 130 ];   // secondary text

	/* ── Layout constants (mm) ──────────────────────────────────────────── */
	const MARGIN_L  = 18;
	const MARGIN_R  = 18;
	const MARGIN_T  = 18;
	const BODY_W    = 174;   // 210 - 18 - 18
	const LINE_H    = 5.5;   // default body line height
	const SECTION_GAP = 8;

	private MYLS_PDF $pdf;
	private string $site_name;
	private string $generated_at;
	private array  $results;

	public function __construct( array $results ) {
		$this->results      = $results;
		$this->site_name    = get_bloginfo( 'name' ) ?: 'AIntelligize';
		$this->generated_at = gmdate( 'F j, Y \a\t g:i A T' );
	}

	/* ── Main entry point ────────────────────────────────────────────────── */

	/** Generate PDF binary string */
	public function generate(): string {
		$this->pdf = new MYLS_PDF( 'P', 'mm', 'A4' );
		$this->pdf->set_margins( self::MARGIN_L, self::MARGIN_T, self::MARGIN_R );
		$this->pdf->set_auto_page_break( true, 22 );

		// Bind footer
		$this->pdf->set_footer_fn( function () {
			$this->render_footer();
		} );

		// ── Cover page ──
		$this->render_cover();

		// ── Per-post analysis pages ──
		foreach ( $this->results as $idx => $result ) {
			$this->render_analysis_page( $idx + 1, count( $this->results ), $result );
		}

		return $this->pdf->output( 'ai-deep-analysis.pdf', 'S' );
	}

	/* ── Cover page ──────────────────────────────────────────────────────── */

	private function render_cover(): void {
		$pdf = $this->pdf;
		$pdf->add_page();

		// Dark hero band
		$pdf->set_fill_color( ...self::C_DARK );
		$pdf->rect( 0, 0, 210, 70, 'F' );

		// Purple accent stripe
		$pdf->set_fill_color( ...self::C_PURPLE );
		$pdf->rect( 0, 68, 210, 4, 'F' );

		// Report title
		$pdf->set_text_color( ...self::C_WHITE );
		$pdf->set_font( 'Helvetica-Bold', 24 );
		$pdf->set_xy( self::MARGIN_L, 22 );
		$pdf->cell( self::BODY_W, 10, 'AI Deep Analysis Report', 0, 1, 'L' );

		$pdf->set_font( 'Helvetica', 13 );
		$pdf->set_text_color( 180, 180, 200 );
		$pdf->set_xy( self::MARGIN_L, 36 );
		$pdf->cell( self::BODY_W, 7, $this->site_name, 0, 1, 'L' );

		$pdf->set_font( 'Helvetica', 10 );
		$pdf->set_text_color( 140, 140, 165 );
		$pdf->set_xy( self::MARGIN_L, 48 );
		$pdf->cell( self::BODY_W, 6, 'Generated: ' . $this->generated_at, 0, 1, 'L' );
		$pdf->set_xy( self::MARGIN_L, 56 );
		$pdf->cell( self::BODY_W, 6, count( $this->results ) . ' page(s) analyzed', 0, 1, 'L' );

		// ── Summary table ──
		$y_start = 82;

		$pdf->set_fill_color( ...self::C_LIGHT_BG );
		$pdf->set_text_color( ...self::C_TEXT );
		$pdf->set_font( 'Helvetica-Bold', 11 );
		$pdf->set_xy( self::MARGIN_L, $y_start );
		$pdf->cell( self::BODY_W, 8, 'Analyzed Pages', 0, 1, 'L' );

		$y = $y_start + 10;

		foreach ( $this->results as $i => $r ) {
			$num   = $i + 1;
			$title = $r['title'] ?? '(unknown)';
			$url   = $r['url']   ?? '';
			$meta  = $r['meta']  ?? [];

			// Alternating row bg
			if ( $num % 2 === 0 ) {
				$pdf->set_fill_color( ...self::C_LIGHT_BG );
				$pdf->rect( self::MARGIN_L, $y, self::BODY_W, 14, 'F' );
			}

			// Row number pill
			$pdf->set_fill_color( ...self::C_PURPLE );
			$pdf->rect( self::MARGIN_L + 1, $y + 2, 8, 9, 'F' );
			$pdf->set_text_color( ...self::C_WHITE );
			$pdf->set_font( 'Helvetica-Bold', 8 );
			$pdf->set_xy( self::MARGIN_L + 1, $y + 4 );
			$pdf->cell( 8, 6, (string) $num, 0, 0, 'C' );

			// Title
			$pdf->set_text_color( ...self::C_TEXT );
			$pdf->set_font( 'Helvetica-Bold', 9.5 );
			$pdf->set_xy( self::MARGIN_L + 12, $y + 2 );
			$pdf->cell( 145, 5, $this->trunc( $title, 70 ), 0, 1, 'L' );

			// URL
			$pdf->set_text_color( ...self::C_MUTED );
			$pdf->set_font( 'Helvetica', 8 );
			$pdf->set_xy( self::MARGIN_L + 12, $y + 7 );
			$pdf->cell( 145, 5, $this->trunc( $url, 80 ), 0, 1, 'L' );

			// Word count + keyword chip
			$wc = $meta['word_count'] ?? 0;
			$kw = $meta['focus_keyword'] ?? '';
			$chips = $wc . ' words';
			if ( $kw ) $chips .= '   KW: ' . $this->trunc( $kw, 30 );
			$pdf->set_text_color( ...self::C_MUTED );
			$pdf->set_font( 'Helvetica', 7.5 );
			$pdf->set_xy( self::MARGIN_L + 12, $y + 12 );
			$pdf->cell( 145, 4, $chips, 0, 0, 'L' );

			$y += 15;

			if ( $pdf->needs_page_break( 20 ) ) {
				$y = 20;
				$pdf->add_page();
			}
		}

		// ── What's inside callout ──
		$y += 8;
		if ( $pdf->needs_page_break( 50 ) ) {
			$pdf->add_page();
			$y = 20;
		}

		$pdf->set_fill_color( ...self::C_PURPLE_BG );
		$pdf->rect( self::MARGIN_L, $y, self::BODY_W, 52, 'F' );

		$pdf->set_fill_color( ...self::C_PURPLE );
		$pdf->rect( self::MARGIN_L, $y, 3, 52, 'F' );

		$pdf->set_text_color( ...self::C_PURPLE );
		$pdf->set_font( 'Helvetica-Bold', 10 );
		$pdf->set_xy( self::MARGIN_L + 7, $y + 4 );
		$pdf->cell( self::BODY_W - 10, 6, 'What This Report Contains', 0, 1, 'L' );

		$sections = [
			'1. Writing Quality & Tone'       => 'Clarity, brand voice, sentence variety, passive voice, emotional resonance.',
			'2. AI Citation Readiness'        => 'Likelihood of citation by AI assistants. Schema, E-E-A-T, FAQ coverage, readiness score.',
			'3. Competitor Gap Opportunities' => '3-5 content angles competitors exploit that your page is missing.',
			'4. Priority Rewrite Recommendations' => 'High-ROI specific changes ranked by impact, with implementation guidance.',
		];

		$sy = $y + 12;
		foreach ( $sections as $label => $desc ) {
			$pdf->set_text_color( ...self::C_TEXT );
			$pdf->set_font( 'Helvetica-Bold', 8.5 );
			$pdf->set_xy( self::MARGIN_L + 7, $sy );
			$pdf->cell( self::BODY_W - 10, 4.5, $label, 0, 1, 'L' );

			$pdf->set_text_color( ...self::C_MUTED );
			$pdf->set_font( 'Helvetica', 8 );
			$pdf->set_xy( self::MARGIN_L + 7, $sy + 4.5 );
			$pdf->cell( self::BODY_W - 10, 4, $desc, 0, 1, 'L' );
			$sy += 11;
		}
	}

	/* ── Per-post analysis page ──────────────────────────────────────────── */

	private function render_analysis_page( int $num, int $total, array $result ): void {
		$pdf     = $this->pdf;
		$title   = $result['title']    ?? '(unknown)';
		$url     = $result['url']      ?? '';
		$meta    = $result['meta']     ?? [];
		$analysis= $result['analysis'] ?? '';

		$pdf->add_page();

		// ── Page header bar ──
		$pdf->set_fill_color( ...self::C_DARK );
		$pdf->rect( 0, 0, 210, 22, 'F' );

		// Page number chip
		$pdf->set_fill_color( ...self::C_PURPLE );
		$pdf->rect( self::MARGIN_L, 4, 14, 13, 'F' );
		$pdf->set_text_color( ...self::C_WHITE );
		$pdf->set_font( 'Helvetica-Bold', 8.5 );
		$pdf->set_xy( self::MARGIN_L, 8 );
		$pdf->cell( 14, 6, "$num / $total", 0, 0, 'C' );

		// Title
		$pdf->set_text_color( ...self::C_WHITE );
		$pdf->set_font( 'Helvetica-Bold', 11 );
		$pdf->set_xy( self::MARGIN_L + 17, 5 );
		$pdf->cell( self::BODY_W - 17, 6, $this->trunc( $title, 75 ), 0, 1, 'L' );

		$pdf->set_text_color( 160, 165, 175 );
		$pdf->set_font( 'Helvetica', 8 );
		$pdf->set_xy( self::MARGIN_L + 17, 12 );
		$pdf->cell( self::BODY_W - 17, 5, $this->trunc( $url, 95 ), 0, 1, 'L' );

		// ── Metadata strip ──
		$y = 28;
		$pdf->set_fill_color( 240, 240, 248 );
		$pdf->rect( self::MARGIN_L, $y, self::BODY_W, 14, 'F' );

		$chips = [
			'Words'       => ( $meta['word_count']    ?? 0 ) . ' words',
			'Keyword'     => $meta['focus_keyword']   ?? '(none)',
			'Location'    => $meta['city_state']      ?? '(none)',
			'Schema'      => ( $meta['has_schema'] ?? false ) ? 'Detected' : 'Not found',
		];
		$cx = self::MARGIN_L + 3;
		foreach ( $chips as $label => $val ) {
			$pdf->set_text_color( ...self::C_MUTED );
			$pdf->set_font( 'Helvetica', 7 );
			$pdf->set_xy( $cx, $y + 2 );
			$pdf->cell( 38, 4, strtoupper( $label ), 0, 1, 'L' );
			$pdf->set_text_color( ...self::C_TEXT );
			$pdf->set_font( 'Helvetica-Bold', 8.5 );
			$pdf->set_xy( $cx, $y + 6 );
			$pdf->cell( 38, 5, $this->trunc( (string) $val, 22 ), 0, 0, 'L' );
			$cx += 43;
		}

		// ── Parse and render the AI analysis ──
		$y_body = $y + 20;

		$sections = $this->parse_analysis_sections( $analysis );

		$section_styles = [
			'WRITING QUALITY'       => [ 'bg' => [ 232, 247, 235 ], 'accent' => self::C_GREEN,  'icon' => 'WRITING' ],
			'AI CITATION'           => [ 'bg' => self::C_PURPLE_BG, 'accent' => self::C_PURPLE, 'icon' => 'AI SIGNAL' ],
			'COMPETITOR GAP'        => [ 'bg' => [ 255, 249, 235 ], 'accent' => [ 180, 100, 0 ],'icon' => 'GAPS' ],
			'PRIORITY REWRITE'      => [ 'bg' => [ 255, 235, 235 ], 'accent' => self::C_RED,    'icon' => 'REWRITES' ],
		];

		foreach ( $sections as $section ) {
			$heading = strtoupper( $section['heading'] );
			$body    = $section['body'];

			// Match style
			$style = [ 'bg' => self::C_LIGHT_BG, 'accent' => self::C_BLUE, 'icon' => '' ];
			foreach ( $section_styles as $key => $s ) {
				if ( strpos( $heading, $key ) !== false ) {
					$style = $s;
					break;
				}
			}

			// Estimate block height
			$lines      = $this->estimate_lines( $body, self::BODY_W - 10 );
			$block_h    = 10 + ( $lines * self::LINE_H ) + 6;

			if ( $pdf->needs_page_break( $block_h + 4 ) ) {
				$pdf->add_page();
				$y_body = 20;
			}

			// Section background
			$pdf->set_fill_color( ...$style['bg'] );
			$pdf->rect( self::MARGIN_L, $y_body, self::BODY_W, $block_h, 'F' );

			// Left accent bar
			$pdf->set_fill_color( ...$style['accent'] );
			$pdf->rect( self::MARGIN_L, $y_body, 3, $block_h, 'F' );

			// Section label chip
			$pdf->set_fill_color( ...$style['accent'] );
			$pdf->rect( self::MARGIN_L + 7, $y_body + 2, 28, 6, 'F' );
			$pdf->set_text_color( ...self::C_WHITE );
			$pdf->set_font( 'Helvetica-Bold', 6.5 );
			$pdf->set_xy( self::MARGIN_L + 7, $y_body + 2.5 );
			$pdf->cell( 28, 5, $style['icon'] ?: $this->trunc( $heading, 18 ), 0, 0, 'C' );

			// Section heading text
			$pdf->set_text_color( ...self::C_TEXT );
			$pdf->set_font( 'Helvetica-Bold', 10 );
			$pdf->set_xy( self::MARGIN_L + 38, $y_body + 2 );
			$pdf->cell( self::BODY_W - 42, 6, $this->trunc( $section['heading'], 70 ), 0, 1, 'L' );

			// Body text
			$pdf->set_text_color( ...self::C_TEXT );
			$pdf->set_font( 'Helvetica', 8.5 );
			$pdf->set_xy( self::MARGIN_L + 7, $y_body + 10 );
			$pdf->multi_cell( self::BODY_W - 10, self::LINE_H, $body, 'L', false );

			$y_body = $pdf->y_mm() + 3;
		}
	}

	/* ── Footer ──────────────────────────────────────────────────────────── */

	private function render_footer(): void {
		$pdf = $this->pdf;
		$y   = $pdf->page_h_mm() - 14;

		$pdf->set_draw_color( 220, 220, 225 );
		$pdf->set_line_width( 0.3 );
		$pdf->line( self::MARGIN_L, $y, 210 - self::MARGIN_R, $y );

		$pdf->set_text_color( ...self::C_MUTED );
		$pdf->set_font( 'Helvetica', 7.5 );

		$pdf->set_xy( self::MARGIN_L, $y + 2 );
		$pdf->cell( 90, 5, 'AIntelligize – AI Deep Analysis Report', 0, 0, 'L' );

		$pdf->set_xy( self::MARGIN_L, $y + 2 );
		$pdf->cell( self::BODY_W, 5, 'Page ' . $pdf->get_page() . ' of {NB}', 0, 0, 'R' );
	}

	/* ── Helpers ─────────────────────────────────────────────────────────── */

	/** Parse the AI response into sections keyed by heading */
	private function parse_analysis_sections( string $analysis ): array {
		$sections = [];
		$lines    = explode( "\n", $analysis );
		$current  = null;
		$body_acc = [];

		foreach ( $lines as $line ) {
			// Section headers: "### 1. WRITING QUALITY..." or "## 1. ..."
			if ( preg_match( '/^#{1,4}\s*\d*\.?\s*(.+)/', $line, $m ) ) {
				if ( $current !== null ) {
					$sections[] = [
						'heading' => $current,
						'body'    => trim( implode( "\n", $body_acc ) ),
					];
				}
				$current  = trim( $m[1] );
				$body_acc = [];
			} elseif ( $current !== null ) {
				$body_acc[] = $line;
			}
		}

		// Last section
		if ( $current !== null && ! empty( $body_acc ) ) {
			$sections[] = [
				'heading' => $current,
				'body'    => trim( implode( "\n", $body_acc ) ),
			];
		}

		// If no sections detected, treat everything as one block
		if ( empty( $sections ) && trim( $analysis ) !== '' ) {
			$sections[] = [ 'heading' => 'Analysis', 'body' => trim( $analysis ) ];
		}

		return $sections;
	}

	/** Estimate rendered line count for a block of text at given width */
	private function estimate_lines( string $txt, float $w_mm ): int {
		$count = 0;
		foreach ( explode( "\n", $txt ) as $para ) {
			$para  = trim( $para );
			$chars = mb_strlen( $para );
			// ~10 chars per 10mm at 8.5pt Helvetica (rough)
			$count += max( 1, (int) ceil( $chars / ( $w_mm * 0.9 ) ) );
		}
		return max( 1, $count );
	}

	/** Truncate string safely */
	private function trunc( string $s, int $max ): string {
		if ( mb_strlen( $s ) <= $max ) return $s;
		return mb_substr( $s, 0, $max - 1 ) . '…';
	}
}
