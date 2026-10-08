<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/** Codice fiscale italiano delle persone fisiche: forma e carattere di controllo (anche con i casi di omocodia). Logica pura. */
final class TaxCode {

	/** Valori dei caratteri nelle posizioni dispari (1ª, 3ª, 5ª…) per il calcolo del carattere di controllo; cifre e lettere A–J coincidono. */
	const ODD = array(
		'0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
		'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
		'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
		'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
	);

	/** Maiuscole e senza spazi. */
	public static function normalize( string $s ): string {
		return strtoupper( (string) preg_replace( '/\s+/', '', $s ) );
	}

	/** Forma (6 lettere, 2 cifre, lettera, 2 cifre, lettera, 3 cifre, lettera; le cifre possono essere lettere LMNPQRSTUV per l'omocodia) e carattere di controllo. */
	public static function is_valid( string $s ): bool {
		$c = self::normalize( $s );
		if ( ! preg_match( '/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $c ) ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < 15; $i++ ) {
			$ch = $c[ $i ];
			if ( 0 === $i % 2 ) { // posizioni dispari (1ª, 3ª…)
				$sum += self::ODD[ $ch ];
			} else {
				$sum += ctype_digit( $ch ) ? (int) $ch : ord( $ch ) - 65;
			}
		}
		return chr( 65 + $sum % 26 ) === $c[15];
	}
}
