<?php
use ApSemplice\Phone;
use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase {

	public function test_same_number_written_in_different_ways(): void {
		foreach ( array( '3331234567', '333 123 4567', '+39 333 123 4567', '0039 333-1234567', '(+39) 333.123.4567', '39 3331234567' ) as $raw ) {
			$this->assertSame( '3331234567', Phone::key( $raw ), $raw );
		}
	}

	public function test_validity(): void {
		$this->assertTrue( Phone::is_valid( '333 1234567' ) );
		$this->assertTrue( Phone::is_valid( '+44 7911 123456' ) );
		$this->assertFalse( Phone::is_valid( '' ) );
		$this->assertFalse( Phone::is_valid( 'abc' ) );
		$this->assertFalse( Phone::is_valid( '12345' ) );
		$this->assertFalse( Phone::is_valid( '1234567890123456789' ) );
	}

	public function test_whatsapp_number(): void {
		$this->assertSame( '393331234567', Phone::whatsapp( '333 123 4567' ) );
		$this->assertSame( '393331234567', Phone::whatsapp( '+39 333 123 4567' ) );
		$this->assertSame( '447911123456', Phone::whatsapp( '+44 7911 123456' ) );
		$this->assertSame( '447911123456', Phone::whatsapp( '0044 7911 123456' ) );
		$this->assertSame( '', Phone::whatsapp( 'boh' ) );
	}
}
