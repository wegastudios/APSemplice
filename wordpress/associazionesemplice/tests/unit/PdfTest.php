<?php
use AssociazioneSemplice\Pdf;
use PHPUnit\Framework\TestCase;

final class PdfTest extends TestCase {

	public function test_document_structure_and_offsets_are_consistent(): void {
		$pdf = new Pdf();
		$pdf->text( 50, 60, 'Ricevuta', 14, true );
		$pdf->line( 50, 70, 500, 70 );
		$pdf->add_page();
		$pdf->text( 50, 60, 'Seconda pagina' );
		$out = $pdf->output();
		$this->assertStringStartsWith( '%PDF-1.4', $out );
		$this->assertStringEndsWith( "%%EOF\n", $out );
		$this->assertStringContainsString( '/Count 2', $out );
		// startxref punta davvero alla tabella "xref"
		preg_match( '/startxref\n(\d+)\n/', $out, $m );
		$this->assertSame( 'xref', substr( $out, (int) $m[1], 4 ) );
		// ogni oggetto sta all'indirizzo dichiarato
		preg_match_all( '/^(\d{10}) 00000 n $/m', $out, $offs );
		foreach ( $offs[1] as $i => $off ) {
			$this->assertSame( ( $i + 1 ) . ' 0 obj', substr( $out, (int) $off, strlen( ( $i + 1 ) . ' 0 obj' ) ) );
		}
	}

	public function test_text_is_encoded_for_winansi_and_escaped(): void {
		$s = Pdf::encode( 'Perché (50 €) a\b' );
		$this->assertStringContainsString( "Perch\xE9", $s );
		$this->assertStringContainsString( "\x80", $s ); // €
		$this->assertStringContainsString( '\(50', $s );
		$this->assertStringContainsString( '\)', $s );
		$this->assertStringContainsString( 'a' . '\\' . '\\' . 'b', $s ); // la barra va raddoppiata
	}

	public function test_width_and_wrap(): void {
		$this->assertEqualsWithDelta( 5 * 0.556 * 10, Pdf::width( '12345', 10 ), 0.001 );
		$this->assertGreaterThan( Pdf::width( 'Totale', 12 ), Pdf::width( 'Totale', 12, true ) );
		$lines = Pdf::wrap( 'Quota associativa 2026 per Mario Rossi e famiglia numerosa', 120, 10 );
		$this->assertGreaterThan( 1, count( $lines ) );
		foreach ( $lines as $l ) {
			$this->assertLessThanOrEqual( 125, Pdf::width( $l, 10 ) );
		}
		$this->assertSame( array( '' ), Pdf::wrap( '', 100 ) );
	}

	public function test_decimal_separator_is_not_locale_dependent(): void {
		$pdf = new Pdf();
		$pdf->text( 50.5, 60.25, 'x' );
		$this->assertMatchesRegularExpression( '/ 50\.50 \d+\.\d{2} Td/', $pdf->output() );
	}
}
