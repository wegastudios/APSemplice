<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/** Situazione pagamenti di una persona in un'attività, mese per mese. */
final class PaymentCalc {

	const PAID    = 'paid';
	const PARTIAL = 'partial';
	const UNPAID  = 'unpaid';
	const ADVANCE = 'advance'; // versato per un mese non (ancora) dovuto

	/**
	 * Mesi dovuti = da $start fino a $end (se cancellato) o fino al mese corrente, dentro l'anno sociale.
	 * I pagamenti sono attribuiti al mese di competenza.
	 *
	 * @param int        $fee           quota mensile in centesimi
	 * @param string     $start         "YYYY-MM" primo mese dovuto
	 * @param string|null $end          "YYYY-MM" ultimo mese dovuto, null se l'iscrizione è attiva
	 * @param string     $today         "YYYY-MM" mese corrente
	 * @param SocialYear $year
	 * @param array      $paid_by_month ['YYYY-MM' => centesimi]
	 * @return array ['months'=>[...], 'total_due', 'total_paid', 'balance', 'regular', 'unpaid_months'=>[...]]
	 */
	public static function compute( int $fee, string $start, ?string $end, string $today, SocialYear $year, array $paid_by_month ): array {
		$last_due = ( null === $end ) ? $today : min( $end, $today );
		$months   = array();
		foreach ( $year->months() as $m ) {
			$due  = ( $m >= $start && $m <= $last_due ) ? $fee : 0;
			$paid = (int) ( $paid_by_month[ $m ] ?? 0 );
			if ( 0 === $due && 0 === $paid ) {
				continue;
			}
			if ( 0 === $due ) {
				$state = self::ADVANCE;
			} elseif ( $paid >= $due ) {
				$state = self::PAID;
			} elseif ( $paid > 0 ) {
				$state = self::PARTIAL;
			} else {
				$state = self::UNPAID;
			}
			$months[] = array( 'month' => $m, 'due' => $due, 'paid' => $paid, 'state' => $state, 'missing' => max( 0, $due - $paid ) );
		}
		$total_due  = array_sum( array_column( $months, 'due' ) );
		$total_paid = array_sum( array_column( $months, 'paid' ) );
		$unpaid     = array_values(
			array_filter(
				$months,
				function ( $x ) {
					return self::UNPAID === $x['state'] || self::PARTIAL === $x['state'];
				}
			)
		);
		return array(
			'months'        => $months,
			'total_due'     => $total_due,
			'total_paid'    => $total_paid,
			'balance'       => $total_paid - $total_due, // >0 credito, <0 da versare
			'regular'       => $total_paid >= $total_due,
			'unpaid_months' => $unpaid,
		);
	}
}
