<?php
use ApSemplice\CardLayout;
use PHPUnit\Framework\TestCase;

final class CardLayoutTest extends TestCase {

	public function test_defaults_are_complete(): void {
		$d = CardLayout::defaults();
		$this->assertSame( array( 'name', 'number', 'valid', 'qr' ), array_keys( $d ) );
		foreach ( $d as $f ) {
			$this->assertSame( array( 'show', 'x', 'y', 'size', 'color' ), array_keys( $f ) );
		}
	}

	public function test_clean_keeps_values_in_range_and_fills_the_rest(): void {
		$c = CardLayout::clean( array( 'name' => array( 'x' => '250', 'y' => '-4', 'size' => '0', 'color' => 'rosso', 'show' => '0' ) ) );
		$this->assertSame( 100.0, $c['name']['x'] );
		$this->assertSame( 0.0, $c['name']['y'] );
		$this->assertSame( 1.0, $c['name']['size'] );
		$this->assertSame( '#222222', $c['name']['color'] );
		$this->assertSame( 0, $c['name']['show'] );
		$this->assertEquals( CardLayout::defaults()["number"], $c["number"] );
	}

	public function test_clean_accepts_garbage(): void {
		$this->assertEquals( CardLayout::defaults(), CardLayout::clean( 'niente' ) );
		$this->assertEquals( CardLayout::defaults(), CardLayout::clean( array( 'name' => 'x' ) ) );
	}
}
