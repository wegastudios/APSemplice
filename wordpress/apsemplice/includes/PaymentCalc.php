<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/** Situazione pagamenti di una persona in un'attività, mese per mese. */
final class PaymentCalc {

	const PAID    = 'paid';
	const PARTIAL = 'partial';
	const UNPAID  = 'unpaid';
	const ADVANCE = 'advance'; // versato per un mese non (ancora) dovuto

	/** Prima data del mese ($month "YYYY-MM") che cade nel giorno della settimana indicato (1 = lunedì … 7 = domenica); senza giorno, il 1° del mese. */
	public static function first_lesson( string $month, $weekday ): string {
		$first = new \DateTimeImmutable( $month . '-01' );
		$days  = array_values( array_filter( array_map( 'intval', (array) $weekday ), function ( $d ) {
			return $d >= 1 && $d <= 7;
		} ) );
		if ( ! $days ) {
			return $first->format( 'Y-m-d' );
		}
		$best = null;
		foreach ( $days as $day ) { // con più giorni a settimana conta la prima lezione tra tutte
			$delta = ( $day - (int) $first->format( 'N' ) + 7 ) % 7;
			$date  = $first->modify( '+' . $delta . ' days' )->format( 'Y-m-d' );
			$best  = null === $best || $date < $best ? $date : $best;
		}
		return $best;
	}

	/**
	 * Corso a pagamento unico (es. "10 incontri a 120 €"): la quota è dovuta per intero dall'iscrizione e si può versare anche a rate.
	 * Se l'iscrizione viene cancellata prima di qualunque pagamento, non è dovuto nulla.
	 *
	 * @param array $paid_by_month tutto ciò che è stato versato, per mese di competenza
	 */
	public static function compute_once( int $fee, string $start, ?string $end, array $paid_by_month ): array {
		$paid = (int) array_sum( $paid_by_month );
		$due  = ( null !== $end && 0 === $paid ) ? 0 : $fee;
		$rows = array();
		if ( $due > 0 || $paid > 0 ) {
			$state  = $paid >= $due ? ( 0 === $due ? self::ADVANCE : self::PAID ) : ( $paid > 0 ? self::PARTIAL : self::UNPAID );
			$rows[] = array( 'month' => $start, 'due' => $due, 'paid' => $paid, 'state' => $state, 'missing' => max( 0, $due - $paid ) );
		}
		return array(
			'months'        => $rows,
			'total_due'     => $due,
			'total_paid'    => $paid,
			'balance'       => $paid - $due,
			'regular'       => $paid >= $due,
			'unpaid_months' => array_values( array_filter( $rows, function ( $x ) {
				return self::UNPAID === $x['state'] || self::PARTIAL === $x['state'];
			} ) ),
			'upcoming'      => null,
		);
	}

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
	 * @param int        $weekday       giorno della lezione (1 = lunedì … 7 = domenica, 0 = non indicato): ogni mese è dovuto dalla prima lezione del mese (altrimenti dal 1°)
	 * @param string|null $today_date  data di oggi "YYYY-MM-DD" (serve solo con il giorno della lezione)
	 * @return array ['months'=>[...], 'total_due', 'total_paid', 'balance', 'regular', 'unpaid_months'=>[...]]
	 */
	public static function compute( int $fee, string $start, ?string $end, string $today, SocialYear $year, array $paid_by_month, $weekday = 0, ?string $today_date = null ): array {
		$last_due = ( null === $end ) ? $today : min( $end, $today );
		$months   = array();
		$upcoming = null;
		foreach ( $year->months() as $m ) {
			$in_range = $m >= $start && $m <= $last_due;
			$from     = self::first_lesson( $m, $weekday );
			$has_days = array_filter( array_map( 'intval', (array) $weekday ) );
			$reached  = null === $today_date || ! $has_days || $m < $today || $from <= $today_date;
			if ( $in_range && ! $reached && null === $upcoming ) {
				$upcoming = array( 'month' => $m, 'date' => $from, 'fee' => $fee );
			}
			$due  = ( $in_range && $reached ) ? $fee : 0;
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
			'upcoming'      => $upcoming, // mese in corso non ancora dovuto: la prima lezione non c'è ancora stata
		);
	}
}
