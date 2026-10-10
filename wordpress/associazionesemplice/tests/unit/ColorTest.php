<?php
use AssociazioneSemplice\Color;
use PHPUnit\Framework\TestCase;

final class ColorTest extends TestCase {
	public function test_normalize(): void {
		$this->assertSame( '#c0392b', Color::normalize( '#C0392B' ) );
		$this->assertSame( '#c0392b', Color::normalize( 'c0392b' ) );
		$this->assertSame( '#aabbcc', Color::normalize( '#ABC' ) );
		$this->assertSame( '', Color::normalize( 'rosso' ) );
		$this->assertSame( '', Color::normalize( '#12345' ) );
		$this->assertSame( '', Color::normalize( 'expression(alert(1))' ) );
		$this->assertSame( '', Color::normalize( '' ) );
	}

	public function test_text_color_is_readable_on_the_background(): void {
		$this->assertSame( '#ffffff', Color::text_on( '#1f6f5c' ), 'verde scuro: testo bianco' );
		$this->assertSame( '#ffffff', Color::text_on( '#000000' ) );
		$this->assertSame( '#1d2327', Color::text_on( '#ffffff' ), 'sfondo bianco: testo scuro' );
		$this->assertSame( '#1d2327', Color::text_on( '#f1c40f' ), 'giallo: testo scuro' );
	}

	public function test_luminance_bounds(): void {
		$this->assertEqualsWithDelta( 0.0, Color::luminance( '#000000' ), 0.0001 );
		$this->assertEqualsWithDelta( 1.0, Color::luminance( '#ffffff' ), 0.0001 );
	}
}
