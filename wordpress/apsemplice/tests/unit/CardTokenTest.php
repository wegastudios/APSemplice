<?php
use ApSemplice\CardToken;
use PHPUnit\Framework\TestCase;

final class CardTokenTest extends TestCase {

	public function test_token_is_signed_and_verifiable(): void {
		$t = CardToken::make( 12, 'segreto' );
		$this->assertSame( 20, strlen( $t ) );
		$this->assertTrue( CardToken::valid( 12, $t, 'segreto' ) );
		$this->assertFalse( CardToken::valid( 13, $t, 'segreto' ), 'la firma è di un altro socio' );
		$this->assertFalse( CardToken::valid( 12, $t, 'altro segreto' ), 'segreto diverso (QR rigenerati)' );
		$this->assertFalse( CardToken::valid( 12, '', 'segreto' ) );
		$this->assertNotSame( CardToken::make( 12, 'a' ), CardToken::make( 13, 'a' ) );
	}

	public function test_parse(): void {
		$p = CardToken::param( 7, 's' );
		$this->assertSame( array( 7, CardToken::make( 7, 's' ) ), CardToken::parse( $p ) );
		$this->assertNull( CardToken::parse( '7' ) );
		$this->assertNull( CardToken::parse( '7.zzzz' ) );
		$this->assertNull( CardToken::parse( 'x.' . str_repeat( 'a', 20 ) ) );
		$this->assertNull( CardToken::parse( '7.' . str_repeat( 'a', 21 ) ) );
		$this->assertNull( CardToken::parse( "7.' OR 1=1" ) );
	}
}
