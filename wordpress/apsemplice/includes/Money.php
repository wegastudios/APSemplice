<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/** Importi sempre in centesimi (int): niente errori di arrotondamento. */
final class Money {

	/** 1234567 => "12.345,67 €" */
	public static function format( int $cents ): string {
		return ( $cents < 0 ? '-' : '' ) . self::plain_grouped( abs( $cents ) ) . ' €';
	}

	/** Senza simbolo né migliaia, per i CSV: 1234,50 */
	public static function plain( int $cents ): string {
		$abs = abs( $cents );
		return ( $cents < 0 ? '-' : '' ) . intdiv( $abs, 100 ) . ',' . sprintf( '%02d', $abs % 100 );
	}

	private static function plain_grouped( int $abs ): string {
		return number_format( intdiv( $abs, 100 ), 0, ',', '.' ) . ',' . sprintf( '%02d', $abs % 100 );
	}

	/** Accetta "12,50", "12.50", "1.500", "1.500,00", "€ 12". null se non valido. */
	public static function parse( ?string $input ): ?int {
		if ( null === $input ) {
			return null;
		}
		$s = trim( str_replace( array( '€', ' ', "\xC2\xA0" ), '', $input ) );
		if ( '' === $s ) {
			return null;
		}
		if ( false !== strpos( $s, ',' ) ) {
			$s = str_replace( '.', '', $s );
			$s = str_replace( ',', '.', $s );
		} elseif ( preg_match( '/^\d{1,3}(\.\d{3})+$/', $s ) ) {
			$s = str_replace( '.', '', $s );
		}
		if ( ! preg_match( '/^-?\d+(\.\d+)?$/', $s ) ) {
			return null;
		}
		return (int) round( ( (float) $s ) * 100 );
	}
}
