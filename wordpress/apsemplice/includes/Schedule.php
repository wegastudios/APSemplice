<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Programma di un'attività descritto a regole: date uniche e giorni ricorrenti con una data di fine
 * (es. "20 settembre dalle 15 alle 20" oppure "tutti i martedì e venerdì dalle 19 alle 20 fino al 31 luglio").
 */
final class Schedule {

	const MAX_DATES = 400;

	const DAYS = array( 1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica' );

	/** Date comprese tra $from e $to (incluse) che cadono nel giorno della settimana indicato (1 = lunedì … 7 = domenica). @return string[] */
	public static function weekly_dates( string $from, string $to, int $weekday ): array {
		$out = array();
		if ( $from > $to || $weekday < 1 || $weekday > 7 ) {
			return $out;
		}
		$d     = new \DateTimeImmutable( $from );
		$delta = ( $weekday - (int) $d->format( 'N' ) + 7 ) % 7;
		$d     = $d->modify( '+' . $delta . ' days' );
		$end   = new \DateTimeImmutable( $to );
		while ( $d <= $end ) {
			$out[] = $d->format( 'Y-m-d' );
			$d     = $d->modify( '+7 days' );
		}
		return $out;
	}

	private static function date( $v ): ?string {
		$v  = trim( (string) $v );
		$dt = \DateTime::createFromFormat( 'Y-m-d', $v );
		return $dt && $dt->format( 'Y-m-d' ) === $v ? $v : null;
	}

	private static function time( $v ): ?string {
		$v = trim( (string) $v );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v ) ? $v : null;
	}

	/** Giorni della settimana validi (1..7) da un elenco qualsiasi. @return int[] */
	public static function days( $raw ): array {
		$out = array();
		foreach ( (array) $raw as $d ) {
			$d = (int) $d;
			if ( $d >= 1 && $d <= 7 ) {
				$out[ $d ] = $d;
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * Espande le regole in date concrete.
	 *
	 * @param array[] $rows ogni riga: type = single (date, from, to) | weekly (days[], from, to, start, end)
	 * @return array[] [ ['date'=>Y-m-d, 'start'=>HH:MM|null, 'end'=>HH:MM|null], ... ] senza doppioni, in ordine
	 * @throws \InvalidArgumentException
	 */
	public static function expand( array $rows, int $max = self::MAX_DATES ): array {
		$out = array();
		foreach ( $rows as $i => $r ) {
			$n     = $i + 1;
			$type  = (string) ( $r['type'] ?? '' );
			$start = self::time( $r['from'] ?? '' );
			$end   = self::time( $r['to'] ?? '' );
			if ( $start && $end && $end <= $start ) {
				throw new \InvalidArgumentException( "Riga $n: l'orario di fine deve essere dopo quello di inizio." );
			}
			if ( 'single' === $type ) {
				$date = self::date( $r['date'] ?? '' );
				if ( ! $date ) {
					throw new \InvalidArgumentException( "Riga $n: indica la data." );
				}
				$out[ $date . '|' . $start ] = array( 'date' => $date, 'start' => $start, 'end' => $end );
			} elseif ( 'weekly' === $type ) {
				$days = self::days( $r['days'] ?? array() );
				$from = self::date( $r['start'] ?? '' );
				$to   = self::date( $r['end'] ?? '' );
				if ( ! $days ) {
					throw new \InvalidArgumentException( "Riga $n: scegli almeno un giorno della settimana." );
				}
				if ( ! $from ) {
					throw new \InvalidArgumentException( "Riga $n: indica da quando inizia." );
				}
				if ( ! $to ) {
					throw new \InvalidArgumentException( "Riga $n: indica la data di fine." );
				}
				if ( $to < $from ) {
					throw new \InvalidArgumentException( "Riga $n: la data di fine è prima di quella di inizio." );
				}
				foreach ( $days as $day ) {
					foreach ( self::weekly_dates( $from, $to, $day ) as $date ) {
						$out[ $date . '|' . $start ] = array( 'date' => $date, 'start' => $start, 'end' => $end );
						if ( count( $out ) > $max ) {
							throw new \InvalidArgumentException( "Troppe date in una volta (massimo $max): accorcia il periodo." );
						}
					}
				}
			} elseif ( '' !== $type ) {
				throw new \InvalidArgumentException( "Riga $n: tipo non valido." );
			}
		}
		$out = array_values( $out );
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['date'] . ( $a['start'] ?: '' ), $b['date'] . ( $b['start'] ?: '' ) );
			}
		);
		return $out;
	}

	/**
	 * Lezioni settimanali di un corso da righe "giorni + dalle + alle": una lezione per ogni giorno scelto.
	 *
	 * @param array[] $rows days[], from, to
	 * @return array[] [ ['day'=>1..7, 'start'=>?, 'end'=>?], ... ]
	 */
	public static function slots( array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			foreach ( self::days( $r['days'] ?? array() ) as $day ) {
				$out[] = array( 'day' => $day, 'start' => self::time( $r['from'] ?? '' ), 'end' => self::time( $r['to'] ?? '' ) );
			}
		}
		return $out;
	}

	/** Raggruppa le lezioni con lo stesso orario in righe "più giorni": per precompilare il modulo. @return array[] days[], from, to */
	public static function rows_from_slots( array $slots ): array {
		$by = array();
		foreach ( $slots as $s ) {
			$key = (string) ( $s['start'] ?? '' ) . '|' . (string) ( $s['end'] ?? '' );
			if ( ! isset( $by[ $key ] ) ) {
				$by[ $key ] = array( 'days' => array(), 'from' => (string) ( $s['start'] ?? '' ), 'to' => (string) ( $s['end'] ?? '' ) );
			}
			$by[ $key ]['days'][] = (int) $s['day'];
		}
		return array_values( $by );
	}
}
