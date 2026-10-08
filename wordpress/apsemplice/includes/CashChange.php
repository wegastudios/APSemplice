<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/** Calcolo del resto per gli incassi in contanti (solo un aiuto: in cassa entra il dovuto). */
final class CashChange {

	/** Tagli euro in centesimi, dal più grande al più piccolo. */
	const DENOMINATIONS = array( 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1 );

	/**
	 * @return array ['ok'=>bool, 'change'=>int, 'missing'=>int, 'breakdown'=>[[taglio, quantità], ...]]
	 */
	public static function compute( int $due, int $tendered ): array {
		if ( $tendered < $due ) {
			return array( 'ok' => false, 'change' => 0, 'missing' => $due - $tendered, 'breakdown' => array() );
		}
		$rest      = $tendered - $due;
		$change    = $rest;
		$breakdown = array();
		foreach ( self::DENOMINATIONS as $d ) {
			$n = intdiv( $rest, $d );
			if ( $n > 0 ) {
				$breakdown[] = array( $d, $n );
				$rest       -= $d * $n;
			}
		}
		return array( 'ok' => true, 'change' => $change, 'missing' => 0, 'breakdown' => $breakdown );
	}

	/** Importi rapidi per "contanti ricevuti": l'esatto + tagli comuni che coprono il dovuto. */
	public static function quick_tenders( int $due ): array {
		if ( $due <= 0 ) {
			return array();
		}
		$out = array( $due );
		foreach ( array( 500, 1000, 2000, 5000, 10000, 20000 ) as $n ) {
			if ( $n >= $due && ! in_array( $n, $out, true ) ) {
				$out[] = $n;
			}
		}
		return array_slice( $out, 0, 4 );
	}
}
