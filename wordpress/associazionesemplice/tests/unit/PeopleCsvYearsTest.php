<?php
use AssociazioneSemplice\PeopleCsv;
use PHPUnit\Framework\TestCase;

final class PeopleCsvYearsTest extends TestCase {

	public function test_year_columns_become_membership_years(): void {
		$r = PeopleCsv::parse_table( array(
			array( 'Nome', 'Cognome', 'N. Telefono', 'N° Tessera', '2024', '2025', '2026' ),
			array( 'Anna', 'Rossi', '333 1234567', '7', 'X', 'x', '' ),
			array( 'Luca', 'Bianchi', '', '8', '', 'sì', 'No' ),
			array( 'Eva', 'Verdi', '', '9', '', '', '' ),
		) );
		$this->assertSame( array( '2024', '2025' ), $r['rows'][0]['years'] );
		$this->assertSame( array( '2025' ), $r['rows'][1]['years'] );
		$this->assertSame( array(), $r['rows'][2]['years'] );
		$this->assertSame( '333 1234567', $r['rows'][0]['phone'], '«N. Telefono» è riconosciuta come telefono' );
		$this->assertSame( '7', $r['rows'][0]['card'] );
	}

	public function test_marks_and_negatives(): void {
		foreach ( array( 'X', 'x', 'sì', '1', 'ok', '✓' ) as $v ) {
			$this->assertTrue( PeopleCsv::is_marked( $v ), $v );
		}
		foreach ( array( null, '', '0', '-', 'no', 'No', 'n' ) as $v ) {
			$this->assertFalse( PeopleCsv::is_marked( $v ), (string) $v );
		}
	}

	public function test_no_year_columns_means_no_years(): void {
		$r = PeopleCsv::parse_table( array( array( 'Nome', 'Cognome', 'Tipo' ), array( 'Anna', 'Rossi', 'ordinario' ) ) );
		$this->assertSame( array(), $r['rows'][0]['years'] );
	}
}
