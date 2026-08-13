<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal dependency-free PDF writer (single page, Helvetica, text + simple lines).
 * Sufficient for generating a personal results sheet without requiring
 * external libraries.
 */
class SwimTiming_PDF {

	private $lines = array(); // Each: array('text'=>..,'size'=>..,'bold'=>bool,'gap_after'=>int)

	public function add_heading( $text ) {
		$this->lines[] = array( 'text' => $text, 'size' => 18, 'bold' => true, 'gap_after' => 10 );
	}

	public function add_subheading( $text ) {
		$this->lines[] = array( 'text' => $text, 'size' => 13, 'bold' => true, 'gap_after' => 8 );
	}

	public function add_text( $text ) {
		$this->lines[] = array( 'text' => $text, 'size' => 11, 'bold' => false, 'gap_after' => 5 );
	}

	public function add_spacer( $height = 6 ) {
		$this->lines[] = array( 'text' => '', 'size' => 4, 'bold' => false, 'gap_after' => $height );
	}

	private function escape_pdf_text( $text ) {
		// Convert to a PDF-safe encoding (WinAnsi covers German umlauts).
		$text = html_entity_decode( htmlentities( $text, ENT_QUOTES, 'UTF-8' ), ENT_QUOTES, 'ISO-8859-1' );
		$text = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
		return $text;
	}

	public function output( $filename ) {
		$page_width = 595.28; // A4
		$page_height = 841.89;
		$margin = 50;
		$y = $page_height - $margin;

		$content = "BT\n";
		foreach ( $this->lines as $line ) {
			$font = $line['bold'] ? '/F2' : '/F1';
			$size = $line['size'];
			$content .= sprintf( "%s %d Tf\n", $font, $size );
			$content .= sprintf( "1 0 0 1 %.2f %.2f Tm\n", $margin, $y );
			$content .= '(' . $this->escape_pdf_text( $line['text'] ) . ") Tj\n";
			$y -= ( $size + $line['gap_after'] );
			if ( $y < $margin ) {
				break; // Single page is sufficient for the personal results sheet.
			}
		}
		$content .= "ET\n";

		$objects = array();
		$objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
		$objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
		$objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$page_width} {$page_height}] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>";
		$objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
		$objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
		$objects[6] = "<< /Length " . strlen( $content ) . " >>\nstream\n{$content}endstream";

		$pdf = "%PDF-1.4\n";
		$offsets = array();
		foreach ( $objects as $num => $body ) {
			$offsets[ $num ] = strlen( $pdf );
			$pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
		}

		$xref_offset = strlen( $pdf );
		$count = count( $objects ) + 1;
		$pdf .= "xref\n0 {$count}\n";
		$pdf .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= count( $objects ); $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref_offset}\n%%EOF";

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore -- raw binary PDF output.
	}
}
