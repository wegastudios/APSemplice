<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Fondi per anno solare: quanto è stato accantonato in ogni anno e quanto di quell'accantonamento è già stato rimborsato o liberato.
 * Rimborsi e liberazioni coprono prima gli accantonamenti più vecchi (primo entrato, primo uscito).
 */
final class FundYears {

	/**
	 * @param array[] $entries kind (share | release | payout), cents (positivo), year (anno solare della registrazione)
	 * @return array<int,array> anno => [accrued, settled, unsettled]  (centesimi)
	 */
	public static function by_year( array $entries ): array {
		$accrued = array();
		$out_sum = 0;
		foreach ( $entries as $e ) {
			$y = (int) $e['year'];
			if ( 'share' === $e['kind'] ) {
				$accrued[ $y ] = ( $accrued[ $y ] ?? 0 ) + (int) $e['cents'];
			} else {
				$out_sum += (int) $e['cents'];
			}
		}
		ksort( $accrued );
		$rows = array();
		foreach ( $accrued as $y => $cents ) {
			$settled       = min( $cents, $out_sum );
			$out_sum      -= $settled;
			$rows[ $y ]    = array( 'accrued' => $cents, 'settled' => $settled, 'unsettled' => $cents - $settled );
		}
		return $rows;
	}

	/** Somma di più fondi (ognuno col suo `by_year`). @param array[] $per_fund */
	public static function totals( array $per_fund ): array {
		$out = array();
		foreach ( $per_fund as $rows ) {
			foreach ( $rows as $y => $r ) {
				foreach ( array( 'accrued', 'settled', 'unsettled' ) as $k ) {
					$out[ (int) $y ][ $k ] = ( $out[ (int) $y ][ $k ] ?? 0 ) + (int) $r[ $k ];
				}
			}
		}
		ksort( $out );
		return $out;
	}
}
