<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Colori per lo stile del front-end (scelti dal selettore colore nelle impostazioni, senza toccare il CSS). */
final class Color {

	/** "#C0392B" / "c0392b" / "#abc" => "#c0392b"; stringa vuota se non è un colore esadecimale valido. */
	public static function normalize( string $hex ): string {
		$h = ltrim( strtolower( trim( $hex ) ), '#' );
		if ( preg_match( '/^[0-9a-f]{3}$/', $h ) ) {
			$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		return preg_match( '/^[0-9a-f]{6}$/', $h ) ? '#' . $h : '';
	}

	/** Luminanza relativa (WCAG) di un colore "#rrggbb". */
	public static function luminance( string $hex ): float {
		$h  = ltrim( $hex, '#' );
		$ch = array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) );
		foreach ( $ch as &$c ) {
			$c = $c / 255;
			$c = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];
	}

	/** Bianco o quasi nero, quello che si legge meglio sopra lo sfondo dato. */
	public static function text_on( string $hex ): string {
		$l           = self::luminance( $hex );
		$on_white    = 1.05 / ( $l + 0.05 );
		$dark        = '#1d2327';
		$on_dark     = ( $l + 0.05 ) / ( self::luminance( $dark ) + 0.05 );
		return $on_white >= $on_dark ? '#ffffff' : $dark;
	}
}
