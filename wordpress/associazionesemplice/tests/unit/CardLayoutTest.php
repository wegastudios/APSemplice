<?php
use AssociazioneSemplice\CardLayout;
use PHPUnit\Framework\TestCase;

final class CardLayoutTest extends TestCase {

	public function test_defaults_are_complete(): void {
		$d = CardLayout::defaults();
		$this->assertSame( array( 'logo', 'name', 'number', 'valid', 'qr' ), array_keys( $d ) );
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

	public function test_text_size_is_limited_but_logo_and_qr_can_be_larger(): void {
		$c = CardLayout::clean( array( 'name' => array( 'size' => '90' ), 'logo' => array( 'size' => '90' ), 'qr' => array( 'size' => '999' ) ) );
		$this->assertSame( 40.0, $c['name']['size'] );
		$this->assertSame( 90.0, $c['logo']['size'] );
		$this->assertSame( 100.0, $c['qr']['size'] );
	}

	public function test_measures_in_centimetres_stay_in_range(): void {
		$this->assertSame( 8.56, CardLayout::clean_cm( '8,56', 5 ) );
		$this->assertSame( 3.0, CardLayout::clean_cm( '0.5', 5 ) );
		$this->assertSame( 30.0, CardLayout::clean_cm( '300', 5 ) );
		$this->assertSame( 5.4, CardLayout::clean_cm( 'abc', 5.4 ) );
	}

	public function test_clean_accepts_garbage(): void {
		$this->assertEquals( CardLayout::defaults(), CardLayout::clean( 'niente' ) );
		$this->assertEquals( CardLayout::defaults(), CardLayout::clean( array( 'name' => 'x' ) ) );
	}
}
