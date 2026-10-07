<?php
use ApSemplice\Fiscal;
use PHPUnit\Framework\TestCase;

final class FiscalTest extends TestCase {

	public function test_valid_vat_numbers(): void {
		foreach ( array( '12345678903', 'IT12345678903', 'it 12345678903', '12345 678903' ) as $v ) {
			$this->assertTrue( Fiscal::is_valid_vat( $v ), $v );
		}
	}

	public function test_invalid_vat_numbers(): void {
		foreach ( array( '', '12345678901', '1234567890', '123456789031', '00000000000', 'ABCDEFGHIJK' ) as $v ) {
			$this->assertFalse( Fiscal::is_valid_vat( $v ), $v );
		}
	}

	public function test_normalize_vat(): void {
		$this->assertSame( '12345678903', Fiscal::normalize_vat( ' IT 123-456.78903 ' ) );
	}

	public function test_entity_tax_code_accepts_eleven_digits_with_check_or_person_code(): void {
		$this->assertTrue( Fiscal::is_valid_entity_tax_code( '12345678903' ) );
		$this->assertFalse( Fiscal::is_valid_entity_tax_code( '12345678901' ) );
		$this->assertTrue( Fiscal::is_valid_entity_tax_code( 'vrdgpp75b10f205b' ) );
		$this->assertFalse( Fiscal::is_valid_entity_tax_code( 'VRDGPP75B10F205C' ) );
		$this->assertFalse( Fiscal::is_valid_entity_tax_code( '' ) );
	}

	public function test_split_from_gross(): void {
		$this->assertSame( array( 'net' => 10000, 'vat' => 2200, 'gross' => 12200 ), Fiscal::split( 12200, 22, true ) );
		$this->assertSame( array( 'net' => 1000, 'vat' => 0, 'gross' => 1000 ), Fiscal::split( 1000, 0, true ) );
	}

	public function test_split_from_net(): void {
		$this->assertSame( array( 'net' => 10000, 'vat' => 2200, 'gross' => 12200 ), Fiscal::split( 10000, 22, false ) );
		$this->assertSame( array( 'net' => 1500, 'vat' => 150, 'gross' => 1650 ), Fiscal::split( 1500, 10, false ) );
	}

	public function test_split_rounds_and_keeps_the_total(): void {
		foreach ( array( 1, 99, 333, 1234, 9999 ) as $cents ) {
			foreach ( array( 22, 10, 5, 4 ) as $rate ) {
				$s = Fiscal::split( $cents, $rate, true );
				$this->assertSame( $cents, $s['net'] + $s['vat'], "$cents @ $rate" );
				$t = Fiscal::split( $cents, $rate, false );
				$this->assertSame( $t['gross'], $t['net'] + $t['vat'], "$cents @ $rate escl." );
			}
		}
	}

	public function test_regimes_and_rates(): void {
		$this->assertArrayHasKey( Fiscal::FLAT, Fiscal::regimes() );
		$this->assertContains( 22, Fiscal::RATES );
		$this->assertTrue( Fiscal::is_rate( 22 ) );
		$this->assertFalse( Fiscal::is_rate( 99 ) );
	}
}
