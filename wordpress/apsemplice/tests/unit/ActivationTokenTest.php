<?php
use ApSemplice\ActivationToken as T;
use PHPUnit\Framework\TestCase;

final class ActivationTokenTest extends TestCase {

	public function test_valid_link(): void {
		$p = T::param( 12, 2000000000, 'segreto' );
		$this->assertSame( array( 12, 2000000000, T::make( 12, 2000000000, 'segreto' ) ), T::parse( $p ) );
		$this->assertSame( 'ok', T::check( $p, 'segreto', 1900000000 ) );
	}

	public function test_expired_forged_or_malformed(): void {
		$p = T::param( 12, 2000000000, 'segreto' );
		$this->assertSame( 'expired', T::check( $p, 'segreto', 2000000001 ) );
		$this->assertSame( 'invalid', T::check( $p, 'altro segreto', 1900000000 ) );
		$this->assertSame( 'invalid', T::check( str_replace( '12.', '13.', $p ), 'segreto', 1900000000 ), 'firma di un altro socio' );
		$this->assertSame( 'invalid', T::check( '12.2000000000.' . str_repeat( 'a', 24 ), 'segreto', 1 ) );
		$this->assertSame( 'invalid', T::check( 'boh', 'segreto', 1 ) );
		$this->assertSame( 'invalid', T::check( '', 'segreto', 1 ) );
		$this->assertSame( 'invalid', T::check( "12.2000000000.' OR 1=1", 'segreto', 1 ) );
	}

	public function test_extending_the_expiry_invalidates_the_signature(): void {
		$p = T::param( 12, 2000000000, 'segreto' );
		$this->assertSame( 'invalid', T::check( str_replace( '2000000000', '2999999999', $p ), 'segreto', 1900000000 ) );
	}
}
