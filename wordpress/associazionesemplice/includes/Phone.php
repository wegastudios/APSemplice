<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Numeri di cellulare. Per gli ospiti è il dato migliore per riconoscere la stessa persona (e per contattarla su WhatsApp):
 * "+39 333 123 4567", "0039 3331234567" e "333-1234567" sono lo stesso numero.
 */
final class Phone {

	const MIN_DIGITS = 8;
	const MAX_DIGITS = 15;

	/** Solo cifre, senza prefisso internazionale italiano (+39 / 0039). Vuoto se non è un numero. */
	public static function key( string $raw ): string {
		$d = (string) preg_replace( '/\D+/', '', $raw );
		if ( 0 === strpos( $d, '00' ) ) {
			$d = substr( $d, 2 );
		}
		if ( 0 === strpos( $d, '39' ) && strlen( $d ) >= 11 && strlen( $d ) <= 13 ) {
			$d = substr( $d, 2 );
		}
		return $d;
	}

	public static function is_valid( string $raw ): bool {
		$n = strlen( self::key( $raw ) );
		return $n >= self::MIN_DIGITS && $n <= self::MAX_DIGITS;
	}

	/** Numero per i link wa.me (con prefisso internazionale; senza prefisso si assume l'Italia). Vuoto se non valido. */
	public static function whatsapp( string $raw ): string {
		if ( ! self::is_valid( $raw ) ) {
			return '';
		}
		$d = (string) preg_replace( '/\D+/', '', $raw );
		if ( 0 === strpos( trim( $raw ), '+' ) ) {
			return $d;
		}
		if ( 0 === strpos( $d, '00' ) ) {
			return substr( $d, 2 );
		}
		return '39' . self::key( $raw );
	}
}
