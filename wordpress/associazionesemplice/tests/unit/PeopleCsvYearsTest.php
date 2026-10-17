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

	public function test_guest_columns_since_and_count(): void {
		$r = PeopleCsv::parse_table( array(
			array( 'Nome', 'Cognome', 'Cellulare', 'Ospite di', 'Volte venuto', 'Eventi passati', 'Anno di iscrizione' ),
			array( 'Anna', 'Rossi', '333 1234567', 'Bianchi', '4 volte', 'Cena 2024, gita 2025', '2019' ),
			array( 'Eva', 'Verdi', '333 7654321', 'Bianchi', 'boh', '', '5/3/2021' ),
		) );
		$this->assertSame( 4, $r['rows'][0]['prior'] );
		$this->assertSame( 'Cena 2024, gita 2025', $r['rows'][0]['notes'] );
		$this->assertSame( '2019-01-01', $r['rows'][0]['since'] );
		$this->assertNull( $r['rows'][1]['prior'] );
		$this->assertNull( $r['rows'][1]['notes'] );
		$this->assertSame( '2021-03-05', $r['rows'][1]['since'] );
		$this->assertSame( '2020-02-29', PeopleCsv::parse_since( '2020-02-29' ) );
		$this->assertNull( PeopleCsv::parse_since( '31/02/2021' ) );
		$this->assertNull( PeopleCsv::parse_since( 'ieri' ) );
	}
}
