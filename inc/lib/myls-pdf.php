<?php
/**
 * MYLS_PDF — Lightweight Pure-PHP PDF Writer
 * File: inc/lib/myls-pdf.php
 *
 * Produces valid PDF 1.4 binary output using only PHP built-ins.
 * No external libraries, no Composer, no system dependencies.
 *
 * Supports:
 *  - Built-in PDF fonts: Helvetica, Helvetica-Bold, Helvetica-Oblique,
 *    Courier, Courier-Bold (no font embedding — every PDF viewer has these)
 *  - RGB fill and text colors
 *  - Filled and stroked rectangles (borders/backgrounds)
 *  - Auto line-wrapping MultiCell
 *  - Automatic page breaks with optional footer callback
 *  - Page numbering via {NB} alias
 *  - A4 and Letter page sizes
 *
 * Usage:
 *   $pdf = new MYLS_PDF( 'P', 'mm', 'A4' );
 *   $pdf->add_page();
 *   $pdf->set_font( 'Helvetica-Bold', 16 );
 *   $pdf->set_fill_color( 31, 35, 39 );
 *   $pdf->rect( 10, 10, 190, 20, 'F' );
 *   $pdf->set_text_color( 255, 255, 255 );
 *   $pdf->set_xy( 10, 14 );
 *   $pdf->cell( 190, 10, 'Hello World', 0, 1, 'C' );
 *   $pdf->output( 'report.pdf', 'D' );   // D = download
 *
 * @since 7.7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MYLS_PDF {

	/* ── Page / document state ─────────────────────────────────────────── */
	private float $page_w;
	private float $page_h;
	private float $margin_l  = 15;
	private float $margin_r  = 15;
	private float $margin_t  = 15;
	private float $margin_b  = 20;
	private float $x         = 15;
	private float $y         = 15;
	private int   $page       = 0;
	private float $line_h     = 6;
	private float $font_size  = 10;

	/* ── Current graphics state ────────────────────────────────────────── */
	private string $font_family = 'Helvetica';
	private array  $fill_color  = [ 255, 255, 255 ];
	private array  $text_color  = [   0,   0,   0 ];
	private array  $draw_color  = [   0,   0,   0 ];
	private float  $line_width  = 0.2;

	/* ── Internal document structures ──────────────────────────────────── */
	private array  $pages        = [];   // raw content streams per page
	private array  $obj_offsets  = [];
	private string $buffer       = '';
	private int    $obj_count    = 0;
	private string $nb_alias     = '{NB}';  // replaced with total page count
	private array  $fonts_used   = [];  // tracks which font names we reference

	/** @var callable|null */
	private $footer_fn = null;

	/* ── Built-in font metrics (Helvetica advance widths, units = 1/1000 em) */
	private array $font_widths = [];

	/* ================================================================== */
	public function __construct( string $orientation = 'P', string $unit = 'mm', string $size = 'A4' ) {
		// Only mm supported (we convert internally)
		$sizes = [
			'A4'     => [ 595.28, 841.89 ],
			'Letter' => [ 612.0,  792.0  ],
		];
		[ $w, $h ] = $sizes[ $size ] ?? $sizes['A4'];

		if ( strtoupper( $orientation ) === 'L' ) {
			$this->page_w = $h;
			$this->page_h = $w;
		} else {
			$this->page_w = $w;
			$this->page_h = $h;
		}

		// Helvetica char widths for the 224 printable WinAnsi chars (indexed 32-255)
		// These are standard PDF spec values × 1000
		$this->font_widths = $this->build_helvetica_widths();
	}

	/* ── Public setters ─────────────────────────────────────────────────── */

	public function set_margins( float $l, float $t, float $r = -1 ): void {
		$this->margin_l = $this->mm2pt( $l );
		$this->margin_t = $this->mm2pt( $t );
		$this->margin_r = $this->mm2pt( $r >= 0 ? $r : $l );
		$this->x        = $this->margin_l;
	}

	public function set_auto_page_break( bool $auto, float $margin = 0 ): void {
		$this->margin_b = $this->mm2pt( $margin );
	}

	public function set_footer_fn( callable $fn ): void {
		$this->footer_fn = $fn;
	}

	public function set_font( string $family, float $size ): void {
		$this->font_family = $family;
		$this->font_size   = $size;
		$this->line_h      = $size * 1.4;   // auto line height
		if ( ! in_array( $family, $this->fonts_used, true ) ) {
			$this->fonts_used[] = $family;
		}
		$this->out_stream( sprintf( "BT /F%d %.2F Tf ET", $this->font_index( $family ), $size ) );
	}

	public function set_line_height( float $lh ): void {
		$this->line_h = $lh;
	}

	public function set_fill_color( int $r, int $g, int $b ): void {
		$this->fill_color = [ $r, $g, $b ];
	}

	public function set_text_color( int $r, int $g, int $b ): void {
		$this->text_color = [ $r, $g, $b ];
	}

	public function set_draw_color( int $r, int $g, int $b ): void {
		$this->draw_color = [ $r, $g, $b ];
	}

	public function set_line_width( float $w ): void {
		$this->line_width = $w;
	}

	public function set_x( float $x ): void { $this->x = $this->mm2pt( $x ); }
	public function set_y( float $y ): void { $this->y = $this->page_h - $this->mm2pt( $y ); }

	public function set_xy( float $x, float $y ): void {
		$this->x = $this->mm2pt( $x );
		$this->y = $this->page_h - $this->mm2pt( $y );
	}

	public function get_x(): float { return $this->pt2mm( $this->x ); }
	public function get_y(): float { return $this->pt2mm( $this->page_h - $this->y ); }
	public function get_page(): int { return $this->page; }

	/* ── Page management ────────────────────────────────────────────────── */

	public function add_page(): void {
		if ( $this->page > 0 ) {
			$this->end_page();
		}
		$this->page++;
		$this->pages[ $this->page ] = '';
		$this->x = $this->margin_l;
		$this->y = $this->page_h - $this->margin_t;

		// Reset graphics state for new page (PDF requires re-issuing state)
		$this->set_line_width_raw( $this->line_width );
	}

	private function end_page(): void {
		if ( $this->footer_fn && $this->page > 0 ) {
			call_user_func( $this->footer_fn );
		}
	}

	/* ── Drawing primitives ─────────────────────────────────────────────── */

	/**
	 * Draw a rectangle.
	 * $style: 'F' = fill, 'D' = stroke, 'FD' = fill+stroke
	 */
	public function rect( float $x, float $y, float $w, float $h, string $style = 'D' ): void {
		$x  = $this->mm2pt( $x );
		$y  = $this->page_h - $this->mm2pt( $y ) - $this->mm2pt( $h );
		$w  = $this->mm2pt( $w );
		$h  = $this->mm2pt( $h );

		$op = match( strtoupper( $style ) ) {
			'F'     => 'f',
			'FD'    => 'B',
			default => 'S',
		};

		$fc = $this->color_cmd( $this->fill_color, 'fill' );
		$dc = $this->color_cmd( $this->draw_color, 'stroke' );

		$this->out_stream( "$fc $dc {$x} {$y} {$w} {$h} re $op" );
	}

	/** Draw a horizontal line */
	public function line( float $x1, float $y1, float $x2, float $y2 ): void {
		$x1 = $this->mm2pt( $x1 ); $y1 = $this->page_h - $this->mm2pt( $y1 );
		$x2 = $this->mm2pt( $x2 ); $y2 = $this->page_h - $this->mm2pt( $y2 );
		$dc = $this->color_cmd( $this->draw_color, 'stroke' );
		$this->out_stream( "$dc $x1 $y1 m $x2 $y2 l S" );
	}

	/* ── Text output ─────────────────────────────────────────────────────── */

	/**
	 * Single-line cell.
	 *
	 * @param float  $w      Cell width in mm (0 = remaining width)
	 * @param float  $h      Cell height in mm
	 * @param string $txt    Text to render
	 * @param int    $border 0|1 (border flag)
	 * @param int    $ln     0=right, 1=newline, 2=below
	 * @param string $align  L|C|R
	 * @param bool   $fill   Fill cell background
	 */
	public function cell(
		float $w, float $h, string $txt = '',
		int $border = 0, int $ln = 0,
		string $align = 'L', bool $fill = false
	): void {
		$w_pt = $w > 0 ? $this->mm2pt( $w ) : ( $this->page_w - $this->x - $this->margin_r );
		$h_pt = $this->mm2pt( $h );

		// Auto page break
		if ( $this->y - $h_pt < $this->margin_b ) {
			$this->add_page();
		}

		$x = $this->x;
		$y = $this->y - $h_pt;

		// Background fill
		if ( $fill ) {
			$fc = $this->color_cmd( $this->fill_color, 'fill' );
			$this->out_stream( "$fc $x $y $w_pt $h_pt re f" );
		}

		// Border
		if ( $border ) {
			$dc = $this->color_cmd( $this->draw_color, 'stroke' );
			$this->out_stream( "$dc $x $y $w_pt $h_pt re S" );
		}

		// Text
		if ( $txt !== '' ) {
			$txt_w   = $this->string_width( $txt );
			$txt_x   = match( strtoupper( $align ) ) {
				'C'     => $x + ( $w_pt - $txt_w ) / 2,
				'R'     => $x + $w_pt - $txt_w - 1,
				default => $x + 1,
			};
			$txt_y = $y + ( $h_pt - $this->font_size * 0.3 ) / 2 + $this->font_size * 0.3;

			$tc  = $this->color_cmd( $this->text_color, 'text' );
			$enc = $this->encode_text( $txt );
			$fi  = $this->font_index( $this->font_family );
			$fs  = $this->font_size;

			$this->out_stream(
				"BT $tc /F$fi $fs Tf $txt_x $txt_y Td ($enc) Tj ET"
			);
		}

		// Move cursor
		if ( $ln === 1 ) {
			$this->x  = $this->margin_l;
			$this->y -= $h_pt;
		} elseif ( $ln === 2 ) {
			$this->x   = $this->margin_l;
			$this->y  -= $h_pt;
		} else {
			$this->x += $w_pt;
		}
	}

	/**
	 * Multi-line cell — auto-wraps text at word boundaries.
	 *
	 * @param float  $w     Cell width in mm
	 * @param float  $h     Line height in mm
	 * @param string $txt   Text (may contain \n for explicit breaks)
	 * @param string $align L|C|R
	 * @param bool   $fill  Fill background
	 */
	public function multi_cell(
		float $w, float $h, string $txt,
		string $align = 'L', bool $fill = false
	): void {
		$lines = $this->split_text( $txt, $w );
		foreach ( $lines as $line ) {
			$this->cell( $w, $h, $line, 0, 1, $align, $fill );
		}
	}

	/** Advance Y by $h mm */
	public function ln( float $h = -1 ): void {
		$this->x  = $this->margin_l;
		$this->y -= $this->mm2pt( $h >= 0 ? $h : $this->pt2mm( $this->line_h ) );
	}

	/* ── Output ──────────────────────────────────────────────────────────── */

	/**
	 * Finalise and output the document.
	 *
	 * @param string $name     Filename for download
	 * @param string $dest     D=download, S=string, F=file path
	 */
	public function output( string $name = 'document.pdf', string $dest = 'D' ): string {
		if ( $this->page > 0 ) {
			$this->end_page();
		}

		$raw = $this->build_pdf();

		// Replace {NB} alias with total page count
		$raw = str_replace( $this->nb_alias, (string) $this->page, $raw );

		if ( $dest === 'S' ) {
			return $raw;
		}

		if ( $dest === 'F' ) {
			file_put_contents( $name, $raw );
			return '';
		}

		// 'D' = download
		if ( headers_sent() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions
			error_log( 'MYLS_PDF: headers already sent, cannot stream PDF.' );
			return $raw;
		}
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $name ) . '"' );
		header( 'Content-Length: ' . strlen( $raw ) );
		header( 'Cache-Control: private, max-age=0, must-revalidate' );
		header( 'Pragma: public' );
		echo $raw;
		return '';
	}

	/* ── Internal PDF serialisation ─────────────────────────────────────── */

	private function build_pdf(): string {
		$buf    = "%PDF-1.4\n%\xc7\xec\x8f\xa2\n";  // binary comment to signal binary file
		$objs   = [];
		$xref   = [];
		$next   = 1;

		// Helper to push object
		$push = function( string $obj_str ) use ( &$buf, &$objs, &$xref, &$next ): int {
			$id          = $next++;
			$xref[ $id ] = strlen( $buf );
			$buf        .= "$id 0 obj\n$obj_str\nendobj\n";
			return $id;
		};

		// ── Font objects ──
		$font_ids = [];
		$all_fonts = [
			'Helvetica'         => 'Helvetica',
			'Helvetica-Bold'    => 'Helvetica-Bold',
			'Helvetica-Oblique' => 'Helvetica-Oblique',
			'Courier'           => 'Courier',
			'Courier-Bold'      => 'Courier-Bold',
			'Times-Roman'       => 'Times-Roman',
			'Times-Bold'        => 'Times-Bold',
		];

		foreach ( $all_fonts as $alias => $pdf_name ) {
			$idx              = $this->font_index( $alias );
			$font_ids[ $idx ] = $push(
				"<< /Type /Font /Subtype /Type1 /BaseFont /$pdf_name /Encoding /WinAnsiEncoding >>"
			);
		}

		// Font dictionary string
		$font_dict = '';
		foreach ( $font_ids as $idx => $id ) {
			$font_dict .= "/F$idx $id 0 R ";
		}

		// ── Content streams (one per page) ──
		$page_ids    = [];
		$stream_ids  = [];
		$pages_id    = $next;  // reserve

		foreach ( $this->pages as $pnum => $content ) {
			$stream_id  = $push( "<<\n/Length " . strlen( $content ) . "\n>>\nstream\n$content\nendstream" );
			$stream_ids[ $pnum ] = $stream_id;
		}

		// ── Page objects ──
		$next++;  // allocate pages_id slot BEFORE page objects
		$pages_id_real = $next - 1;
		// Rebuild: pages_id was set to $next before the increment, fix it
		$pages_id = $next - 1;

		foreach ( $this->pages as $pnum => $content ) {
			$sid = $stream_ids[ $pnum ];
			$page_ids[ $pnum ] = $push(
				"<<\n/Type /Page\n/Parent $pages_id 0 R\n" .
				"/MediaBox [0 0 {$this->page_w} {$this->page_h}]\n" .
				"/Resources << /Font << $font_dict >> >>\n" .
				"/Contents $sid 0 R\n>>"
			);
		}

		// ── Pages tree ──
		$kids = implode( ' 0 R ', $page_ids ) . ' 0 R';
		$xref[ $pages_id ] = strlen( $buf );
		$buf .= "$pages_id 0 obj\n<<\n/Type /Pages\n/Kids [$kids]\n/Count {$this->page}\n>>\nendobj\n";

		// ── Catalog ──
		$catalog_id = $push( "<<\n/Type /Catalog\n/Pages $pages_id 0 R\n>>" );

		// ── Cross-reference table ──
		$xref_offset = strlen( $buf );
		$obj_total   = $next;

		$buf .= "xref\n0 $obj_total\n";
		$buf .= sprintf( "%010d %05d f \n", 0, 65535 );
		for ( $i = 1; $i < $obj_total; $i++ ) {
			if ( isset( $xref[ $i ] ) ) {
				$buf .= sprintf( "%010d %05d n \n", $xref[ $i ], 0 );
			} else {
				$buf .= sprintf( "%010d %05d f \n", 0, 65535 );
			}
		}

		$buf .= "trailer\n<<\n/Size $obj_total\n/Root $catalog_id 0 R\n>>\n";
		$buf .= "startxref\n$xref_offset\n%%EOF\n";

		return $buf;
	}

	/* ── Stream helpers ─────────────────────────────────────────────────── */

	private function out_stream( string $cmd ): void {
		if ( $this->page > 0 ) {
			$this->pages[ $this->page ] .= $cmd . "\n";
		}
	}

	private function set_line_width_raw( float $w ): void {
		$this->out_stream( sprintf( "%.3F w", $w ) );
	}

	/* ── Text helpers ───────────────────────────────────────────────────── */

	/** Convert a UTF-8 string to WinAnsi (Latin-1) and escape for PDF string syntax */
	private function encode_text( string $txt ): string {
		// Transliterate common UTF-8 chars to ASCII equivalents
		$map = [
			"\xe2\x80\x99" => "'",   // right single quote
			"\xe2\x80\x98" => "'",   // left single quote
			"\xe2\x80\x9c" => '"',   // left double quote
			"\xe2\x80\x9d" => '"',   // right double quote
			"\xe2\x80\x93" => '-',   // en dash
			"\xe2\x80\x94" => '--',  // em dash
			"\xe2\x80\xa6" => '...', // ellipsis
			"\xc2\xa9"     => '(c)', // copyright
			"\xc2\xae"     => '(R)', // registered
			"\xc2\xb0"     => 'deg', // degree
			"\xe2\x80\xa2" => '*',   // bullet
			"\xe2\x9c\x85" => '[OK]',
			"\xe2\x9d\x8c" => '[X]',
			"\xe2\x9a\xa0" => '[!]',
			"\xf0\x9f\x9f\xa2" => '[GREEN]',
			"\xf0\x9f\x9f\xa1" => '[YELLOW]',
			"\xf0\x9f\x94\xb4" => '[RED]',
			"\xe2\xad\x90" => '*',   // star
		];
		$txt = strtr( $txt, $map );
		// Strip any remaining multibyte (emoji etc.) — keep ASCII + Latin-1
		$txt = preg_replace( '/[\x80-\xBF]/', '', $txt );
		$txt = preg_replace( '/[\xC2-\xDF][\x80-\xBF]/', '', $txt );
		$txt = preg_replace( '/[\xE0-\xEF][\x80-\xBF]{2}/', '', $txt );
		$txt = preg_replace( '/[\xF0-\xF4][\x80-\xBF]{3}/', '', $txt );

		// PDF string escaping
		$txt = str_replace( '\\', '\\\\', $txt );
		$txt = str_replace( '(',  '\\(',  $txt );
		$txt = str_replace( ')',  '\\)',  $txt );
		return $txt;
	}

	/** Calculate string width in PDF points for current font/size */
	private function string_width( string $txt ): float {
		$w     = 0;
		$chars = str_split( $txt );
		foreach ( $chars as $ch ) {
			$code = ord( $ch );
			$w   += $this->font_widths[ $code ] ?? 556;
		}
		return $w * $this->font_size / 1000;
	}

	/** Split text into lines that fit within $max_w mm */
	private function split_text( string $txt, float $max_w ): array {
		$max_pt  = $this->mm2pt( $max_w ) - 2;  // 1mm inner padding each side
		$lines   = [];

		foreach ( explode( "\n", $txt ) as $para ) {
			$para = trim( $para );
			if ( $para === '' ) {
				$lines[] = '';
				continue;
			}
			$words  = explode( ' ', $para );
			$line   = '';
			foreach ( $words as $word ) {
				$test = $line === '' ? $word : "$line $word";
				if ( $this->string_width( $test ) > $max_pt && $line !== '' ) {
					$lines[] = $line;
					$line    = $word;
				} else {
					$line = $test;
				}
			}
			if ( $line !== '' ) {
				$lines[] = $line;
			}
		}
		return $lines;
	}

	/* ── Color helpers ──────────────────────────────────────────────────── */

	private function color_cmd( array $rgb, string $type ): string {
		[ $r, $g, $b ] = $rgb;
		$r = round( $r / 255, 3 );
		$g = round( $g / 255, 3 );
		$b = round( $b / 255, 3 );
		return $type === 'text'
			? "$r $g $b rg"      // non-stroking (text/fill)
			: ( $type === 'fill' ? "$r $g $b rg" : "$r $g $b RG" );  // stroking
	}

	/* ── Unit conversion ────────────────────────────────────────────────── */

	private function mm2pt( float $mm ): float { return $mm * 2.83465; }
	private function pt2mm( float $pt ): float { return $pt / 2.83465; }

	/* ── Font index registry ─────────────────────────────────────────────── */

	private array $font_map = [];
	private int   $font_seq = 0;

	private function font_index( string $family ): int {
		if ( ! isset( $this->font_map[ $family ] ) ) {
			$this->font_seq++;
			$this->font_map[ $family ] = $this->font_seq;
		}
		return $this->font_map[ $family ];
	}

	/* ── Helvetica widths table (standard PDF values) ────────────────────── */

	private function build_helvetica_widths(): array {
		// Standard Helvetica advance widths for char codes 0-255 (units: 1/1000 em)
		$w = array_fill( 0, 256, 278 );  // default width

		$table = [
			32  => 278,  33  => 278,  34  => 355,  35  => 556,  36  => 556,
			37  => 889,  38  => 667,  39  => 222,  40  => 333,  41  => 333,
			42  => 389,  43  => 584,  44  => 278,  45  => 333,  46  => 278,
			47  => 278,  48  => 556,  49  => 556,  50  => 556,  51  => 556,
			52  => 556,  53  => 556,  54  => 556,  55  => 556,  56  => 556,
			57  => 556,  58  => 278,  59  => 278,  60  => 584,  61  => 584,
			62  => 584,  63  => 556,  64  => 1015, 65  => 667,  66  => 667,
			67  => 722,  68  => 722,  69  => 667,  70  => 611,  71  => 778,
			72  => 722,  73  => 278,  74  => 500,  75  => 667,  76  => 556,
			77  => 833,  78  => 722,  79  => 778,  80  => 667,  81  => 778,
			82  => 722,  83  => 667,  84  => 611,  85  => 722,  86  => 667,
			87  => 944,  88  => 667,  89  => 667,  90  => 611,  91  => 278,
			92  => 278,  93  => 278,  94  => 469,  95  => 556,  96  => 222,
			97  => 556,  98  => 556,  99  => 500,  100 => 556,  101 => 556,
			102 => 278,  103 => 556,  104 => 556,  105 => 222,  106 => 222,
			107 => 500,  108 => 222,  109 => 833,  110 => 556,  111 => 556,
			112 => 556,  113 => 556,  114 => 333,  115 => 500,  116 => 278,
			117 => 556,  118 => 500,  119 => 722,  120 => 500,  121 => 500,
			122 => 500,  123 => 334,  124 => 260,  125 => 334,  126 => 584,
		];

		foreach ( $table as $code => $width ) {
			$w[ $code ] = $width;
		}
		return $w;
	}

	/* ── Convenience helpers for report builders ─────────────────────────── */

	/** Remaining printable width in mm */
	public function printable_w(): float {
		return $this->pt2mm( $this->page_w - $this->margin_l - $this->margin_r );
	}

	/** Current Y position in mm from top */
	public function y_mm(): float {
		return $this->pt2mm( $this->page_h - $this->y );
	}

	/** Check if we need a new page given a block height */
	public function needs_page_break( float $block_h_mm ): bool {
		$y_remaining = $this->y - $this->margin_b;
		return $y_remaining < $this->mm2pt( $block_h_mm );
	}

	/** Total page height in mm */
	public function page_h_mm(): float {
		return $this->pt2mm( $this->page_h );
	}
}
