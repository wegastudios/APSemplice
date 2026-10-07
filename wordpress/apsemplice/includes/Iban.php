<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/** Controllo e scrittura degli IBAN (ISO 13616, cifra di controllo mod 97). Logica pura. */
final class Iban {

	/** Lunghezze fisse dei paesi più comuni; per gli altri basta una lunghezza plausibile (15–34). */
	const LENGTHS = array(
		'IT' => 27, 'SM' => 27, 'VA' => 22, 'CH' => 21, 'LI' => 21, 'FR' => 27, 'MC' => 27, 'DE' => 22, 'ES' => 24, 'PT' => 25, 'GB' => 22, 'IE' => 22,
		'NL' => 18, 'BE' => 16, 'LU' => 20, 'AT' => 20, 'MT' => 31, 'GR' => 27, 'CY' => 28, 'SI' => 19, 'HR' => 21, 'SK' => 24, 'PL' => 28,
	);

	/** Maiuscole, senza spazi, punti o trattini. */
	public static function normalize( string $s ): string {
		return strtoupper( (string) preg_replace( '/[\s\.\-]+/', '', $s ) );
	}

	public static function is_valid( string $s ): bool {
		$n = self::normalize( $s );
		if ( ! preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $n ) ) {
			return false;
		}
		$cc = substr( $n, 0, 2 );
		if ( isset( self::LENGTHS[ $cc ] ) && strlen( $n ) !== self::LENGTHS[ $cc ] ) {
			return false;
		}
		return 1 === self::mod97( substr( $n, 4 ) . substr( $n, 0, 4 ) );
	}

	/** Resto della divisione per 97 della stringa con le lettere sostituite da numeri (A = 10 … Z = 35), calcolato a pezzi. */
	private static function mod97( string $s ): int {
		$digits = '';
		foreach ( str_split( $s ) as $ch ) {
			$digits .= ctype_alpha( $ch ) ? (string) ( ord( $ch ) - 55 ) : $ch;
		}
		$rem = 0;
		foreach ( str_split( $digits, 7 ) as $chunk ) {
			$rem = (int) ( ( $rem . $chunk ) % 97 );
		}
		return $rem;
	}

	/** Gruppi di quattro caratteri: «IT60 X054 2811 1010 0000 0123 456». */
	public static function format( string $s ): string {
		return trim( implode( ' ', str_split( self::normalize( $s ), 4 ) ) );
	}

	/** Per i registri: paese e ultime quattro cifre («IT** **** 3456»). */
	public static function mask( string $s ): string {
		$n = self::normalize( $s );
		return strlen( $n ) < 8 ? '' : substr( $n, 0, 2 ) . '** **** ' . substr( $n, -4 );
	}
}
