<?php
use ApSemplice\FundShare;
use PHPUnit\Framework\TestCase;

final class FundShareTest extends TestCase {

	public function test_fixed_share_never_exceeds_the_payment(): void {
		$this->assertSame( 200, FundShare::cents( 'fixed', 200, 1000 ) );
		$this->assertSame( 150, FundShare::cents( 'fixed', 200, 150 ) );
	}

	public function test_percent_share_rounds_down(): void {
		$this->assertSame( 500, FundShare::cents( 'percent', 2500, 2000 ) );   // 25%
		$this->assertSame( 333, FundShare::cents( 'percent', 3333, 1000 ) );   // 33,33% di 10,00 -> 3,33
		$this->assertSame( 1000, FundShare::cents( 'percent', 10000, 1000 ) );
		$this->assertSame( 1000, FundShare::cents( 'percent', 99999, 1000 ) ); // mai oltre il 100%
	}

	public function test_nothing_without_a_rule_or_amount(): void {
		$this->assertSame( 0, FundShare::cents( '', 500, 1000 ) );
		$this->assertSame( 0, FundShare::cents( 'fixed', 0, 1000 ) );
		$this->assertSame( 0, FundShare::cents( 'percent', 2500, 0 ) );
		$this->assertSame( 0, FundShare::cents( 'boh', 500, 1000 ) );
	}

	public function test_validation(): void {
		$this->assertSame( array(), FundShare::validate( '', 0, false ) );
		$this->assertSame( array(), FundShare::validate( 'fixed', 200, true ) );
		$this->assertNotEmpty( FundShare::validate( 'fixed', 0, true ) );
		$this->assertNotEmpty( FundShare::validate( 'percent', 10100, true ) );
		$this->assertNotEmpty( FundShare::validate( 'percent', 2500, false ) );
		$this->assertNotEmpty( FundShare::validate( 'altro', 100, true ) );
	}
}
