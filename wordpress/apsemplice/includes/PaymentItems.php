<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Le "voci da pagare" di un pagamento online. Ogni voce è un importo dovuto da un partecipante (il socio o un suo ospite):
 *
 *  - membership    quota associativa dell'anno sociale           chiave  q:{persona}:{anno sociale}
 *  - course_month  mensilità (o quota) di un corso per un mese    chiave  m:{attività}:{persona}:{YYYY-MM}
 *  - booking       contributo di una prenotazione a un evento    chiave  b:{data}:{persona}
 *
 * Il browser invia solo le CHIAVI scelte; importi e descrizioni si ricalcolano sempre sul server.
 */
final class PaymentItems {

	const MEMBERSHIP   = 'membership';
	const COURSE_MONTH = 'course_month';
	const BOOKING      = 'booking';

	/** Importo minimo accettato dai gateway (Stripe: 0,50 €). */
	const MIN_TOTAL_CENTS = 50;

	public static function key( array $i ): string {
		switch ( $i['type'] ) {
			case self::MEMBERSHIP:
				return 'q:' . (int) $i['person_id'] . ':' . $i['social_year'];
			case self::COURSE_MONTH:
				return 'm:' . (int) $i['activity_id'] . ':' . (int) $i['person_id'] . ':' . $i['month'];
			default:
				return 'b:' . (int) $i['session_id'] . ':' . (int) $i['person_id'];
		}
	}

	/** @return array|null ['type', 'person_id', + attività / data / mese / anno sociale], null se la chiave non è valida */
	public static function parse( string $key ): ?array {
		$p = explode( ':', $key );
		if ( 'q' === $p[0] && 3 === count( $p ) && ctype_digit( $p[1] ) && preg_match( '#^\d{4}(/\d{4})?$#', $p[2] ) ) {
			return array( 'type' => self::MEMBERSHIP, 'person_id' => (int) $p[1], 'social_year' => $p[2] );
		}
		if ( 'm' === $p[0] && 4 === count( $p ) && ctype_digit( $p[1] ) && ctype_digit( $p[2] ) && preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $p[3] ) ) {
			return array( 'type' => self::COURSE_MONTH, 'activity_id' => (int) $p[1], 'person_id' => (int) $p[2], 'month' => $p[3] );
		}
		if ( 'b' === $p[0] && 3 === count( $p ) && ctype_digit( $p[1] ) && ctype_digit( $p[2] ) ) {
			return array( 'type' => self::BOOKING, 'session_id' => (int) $p[1], 'person_id' => (int) $p[2] );
		}
		return null;
	}

	public static function total( array $items ): int {
		return (int) array_sum( array_map( function ( $i ) {
			return (int) $i['amount_cents'];
		}, $items ) );
	}

	/** Voci raggruppate per partecipante: [person_id => [voci]]. */
	public static function group_by_person( array $items ): array {
		$out = array();
		foreach ( $items as $i ) {
			$out[ (int) $i['person_id'] ][] = $i;
		}
		return $out;
	}

	/** 1250 => "12.50" (formato dei gateway). */
	public static function decimal( int $cents ): string {
		return intdiv( $cents, 100 ) . '.' . sprintf( '%02d', $cents % 100 );
	}

	/** Testo breve per la riga di un gateway o per la ricevuta. */
	public static function line_name( array $i ): string {
		$name = trim( (string) ( $i['label'] ?? '' ) );
		$who  = trim( (string) ( $i['person_name'] ?? '' ) );
		$txt  = '' !== $who ? $name . ' — ' . $who : $name;
		return mb_substr( '' !== $txt ? $txt : 'Pagamento', 0, 120 );
	}

	/** Descrizione complessiva del pagamento. */
	public static function summary( array $items, string $association = '' ): string {
		$n   = count( $items );
		$txt = 1 === $n ? self::line_name( $items[0] ) : $n . ' voci';
		return mb_substr( ( '' !== $association ? $association . ': ' : '' ) . $txt, 0, 120 );
	}
}
