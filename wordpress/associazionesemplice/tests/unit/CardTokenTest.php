<?php
use AssociazioneSemplice\CardToken;
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

	public function test_ticket_token_binds_session_and_person(): void {
		$t = CardToken::ticket_make( 5, 9, 's' );
		$this->assertTrue( CardToken::ticket_valid( 5, 9, $t, 's' ) );
		$this->assertFalse( CardToken::ticket_valid( 6, 9, $t, 's' ), 'altra data' );
		$this->assertFalse( CardToken::ticket_valid( 5, 10, $t, 's' ), 'altra persona' );
		$this->assertFalse( CardToken::ticket_valid( 5, 9, $t, 'x' ), 'QR rigenerati' );
		$this->assertNotSame( CardToken::make( 5, 's' ), CardToken::ticket_make( 5, 9, 's' ), 'un biglietto non è una tessera' );
		$this->assertSame( array( 5, 9, $t ), CardToken::ticket_parse( CardToken::ticket_param( 5, 9, 's' ) ) );
		$this->assertNull( CardToken::ticket_parse( '5.9' ) );
		$this->assertNull( CardToken::ticket_parse( '5.9.zz' ) );
		$this->assertNull( CardToken::ticket_parse( CardToken::param( 5, 's' ) ), 'una tessera non si legge come biglietto' );
	}
}
