<?php
use ApSemplice\QrCode;
use PHPUnit\Framework\TestCase;

final class QrCodeTest extends TestCase {

	public function test_reed_solomon_matches_the_standard_example(): void {
		// Esempio dello standard ISO 18004 (versione 1-M, "01234567")
		$data = array( 16, 32, 12, 86, 97, 128, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17 );
		$this->assertSame( array( 165, 36, 212, 193, 237, 54, 199, 135, 44, 85 ), QrCode::rs_encode( $data, 10 ) );
	}

	public function test_format_and_version_information_bits(): void {
		$expected = array( 0x5412, 0b101000100100101, 0b101111001111100, 0b101101101001011, 0b100010111111001, 0b100000011001110, 0b100111110010111, 0b100101010100000 );
		foreach ( $expected as $mask => $bits ) {
			$this->assertSame( $bits, QrCode::format_bits( $mask ), "maschera $mask" );
		}
		$this->assertSame( 0b000111110010010100, QrCode::version_bits( 7 ) );
		$this->assertSame( 0b001000010110111100, QrCode::version_bits( 8 ) );
		$this->assertSame( 0b001001101010011001, QrCode::version_bits( 9 ) );
		$this->assertSame( 0b001010010011010011, QrCode::version_bits( 10 ) );
	}

	public function test_version_is_chosen_by_length(): void {
		$limits = array( 1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84, 6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213 );
		foreach ( $limits as $v => $max ) {
			$this->assertSame( $v, QrCode::version_for( $max ), "$max byte stanno nella versione $v" );
			if ( $v < 10 ) {
				$this->assertSame( $v + 1, QrCode::version_for( $max + 1 ), ( $max + 1 ) . ' byte passano alla versione ' . ( $v + 1 ) );
			}
		}
		$this->assertNull( QrCode::version_for( 214 ) );
	}

	public function test_codeword_count_matches_the_version(): void {
		$totals = array( 1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134, 6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346 );
		foreach ( $totals as $v => $total ) {
			$this->assertCount( $total, QrCode::codewords( 'x', $v ), "parole di codice della versione $v" );
		}
	}

	public function test_matrix_structure(): void {
		$m = QrCode::matrix( 'https://example.org/?apse_card=1.abcdefabcdefabcdefab' );
		$n = count( $m );
		$this->assertSame( 17 + 4 * 4, $n, 'versione 4' );
		foreach ( $m as $row ) {
			$this->assertCount( $n, $row );
		}
		// modelli di posizione negli angoli (7x7 con centro 3x3 pieno) e separatori bianchi
		foreach ( array( array( 0, 0 ), array( 0, $n - 7 ), array( $n - 7, 0 ) ) as $o ) {
			for ( $i = 0; $i < 7; $i++ ) {
				$this->assertTrue( $m[ $o[0] ][ $o[1] + $i ] && $m[ $o[0] + 6 ][ $o[1] + $i ] && $m[ $o[0] + $i ][ $o[1] ] && $m[ $o[0] + $i ][ $o[1] + 6 ] );
			}
			$this->assertFalse( $m[ $o[0] + 1 ][ $o[1] + 1 ] );
			$this->assertTrue( $m[ $o[0] + 3 ][ $o[1] + 3 ] );
		}
		for ( $i = 8; $i < $n - 8; $i++ ) { // temporizzazione
			$this->assertSame( 0 === $i % 2, $m[6][ $i ] );
			$this->assertSame( 0 === $i % 2, $m[ $i ][6] );
		}
		$this->assertTrue( $m[ $n - 8 ][8], 'modulo scuro fisso' );
	}

	public function test_is_deterministic_and_data_dependent(): void {
		$this->assertSame( QrCode::matrix( 'ciao' ), QrCode::matrix( 'ciao' ) );
		$this->assertNotSame( QrCode::matrix( 'ciao' ), QrCode::matrix( 'cian' ) );
	}

	public function test_too_long_text_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		QrCode::matrix( str_repeat( 'a', 214 ) );
	}

	public function test_svg_output(): void {
		$svg = QrCode::svg( 'https://example.org/', 4, 'Prova <b>' );
		$this->assertStringStartsWith( '<svg ', $svg );
		$this->assertStringContainsString( 'viewBox="0 0 33 33"', $svg ); // versione 2 (25) + 2×4
		$this->assertStringContainsString( 'aria-label="Prova &lt;b&gt;"', $svg );
		$this->assertStringNotContainsString( '<b>', $svg );
	}
}
