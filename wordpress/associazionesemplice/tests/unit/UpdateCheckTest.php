<?php
use AssociazioneSemplice\UpdateCheck as U;
use PHPUnit\Framework\TestCase;

final class UpdateCheckTest extends TestCase {

	public function test_only_plain_version_numbers_are_valid(): void {
		foreach ( array( '1.1.13', '1.2', '2.0.0.1' ) as $v ) {
			$this->assertTrue( U::valid_version( $v ), $v );
		}
		foreach ( array( '', '1', 'v1.2', '1.2-beta', '1.2.3.4.5', '<script>', '1.1.13 ' ) as $v ) {
			$this->assertFalse( U::valid_version( $v ), $v );
		}
	}

	public function test_newer_compares_numerically(): void {
		$this->assertTrue( U::is_newer( '1.1.9', '1.1.10' ) );
		$this->assertTrue( U::is_newer( '1.1.20', '1.2' ) );
		$this->assertFalse( U::is_newer( '1.1.13', '1.1.13' ) );
		$this->assertFalse( U::is_newer( '1.2', '1.1.20' ) );
		$this->assertFalse( U::is_newer( 'boh', '1.2' ) );
	}

	public function test_parse_keeps_only_valid_entries_and_https_urls(): void {
		$r = U::parse( '{"free":{"version":"1.1.13","url":"https://associazionesemplice.it/scarica/free/1.1.13"},"pro":{"version":"1.1.5","url":"http://x.it/a"}}' );
		$this->assertSame( '1.1.13', $r['free']['version'] );
		$this->assertSame( 'https://associazionesemplice.it/scarica/free/1.1.13', $r['free']['url'] );
		$this->assertSame( '', $r['pro']['url'], 'indirizzo non https scartato' );
		$this->assertSame( array(), U::parse( 'non json' ) );
		$this->assertSame( array(), U::parse( '{"free":{"version":"<b>"},"pro":"x"}' ) );
		$this->assertSame( array( 'pro' ), array_keys( U::parse( '{"free":{"version":1.1},"pro":{"version":"1.2"}}' ) ) );
	}
}
