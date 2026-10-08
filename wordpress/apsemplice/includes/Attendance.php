<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Registro presenze: per ogni lezione di un corso (o data di un evento) si segna chi c'era.
 * Le righe sono per attività + data + persona, così valgono per corsi (lezioni settimanali) ed eventi (date).
 */
final class Attendance {

	private static function db(): \wpdb {
		return Db::db();
	}

	/** Date in cui si tiene l'attività tra $from e $to (incluse): lezioni dei corsi o date degli eventi (non annullate). @return string[] */
	public static function lesson_dates( array $a, string $from, string $to ): array {
		$dates = array();
		if ( ActivityKind::uses_sessions( $a['kind'] ) ) {
			$rows = self::db()->get_col( self::db()->prepare( 'SELECT session_date FROM ' . Db::t( 'sessions' ) . ' WHERE activity_id = %d AND cancelled_at IS NULL AND session_date BETWEEN %s AND %s ORDER BY session_date', (int) $a['id'], $from, $to ) ) ?: array();
			return array_values( array_unique( array_map( 'strval', $rows ) ) );
		}
		foreach ( ActivityService::lessons( $a ) as $s ) {
			if ( 'single' === $s['type'] ) {
				if ( $s['date'] >= $from && $s['date'] <= $to ) {
					$dates[] = $s['date'];
				}
				continue;
			}
			$lo = max( $from, (string) ( $s['from'] ?: ( $a['starts_on'] ?: $from ) ) );
			$hi = min( $to, (string) ( $s['until'] ?: ( $a['ends_on'] ?: $to ) ) );
			foreach ( Schedule::weekly_dates( $lo, $hi, (int) $s['day'] ) as $d ) {
				$dates[] = $d;
			}
		}
		$dates = array_values( array_unique( $dates ) );
		sort( $dates );
		return $dates;
	}

	public static function is_lesson_date( array $a, string $date ): bool {
		return in_array( $date, self::lesson_dates( $a, $date, $date ), true );
	}

	/** Chi è atteso a quella data: iscritti al corso in quel mese, o prenotati alla data dell'evento. @return array[] */
	public static function roster( array $a, string $date ): array {
		$db = self::db();
		$p  = Db::t( 'people' );
		if ( ActivityKind::uses_sessions( $a['kind'] ) ) {
			$sql = "SELECT DISTINCT p.id, p.first_name, p.last_name, p.type, p.card_number FROM $p p JOIN " . Db::t( 'bookings' ) . ' b ON b.person_id = p.id AND b.status = \'booked\' JOIN ' . Db::t( 'sessions' )
				. ' s ON s.id = b.session_id AND s.cancelled_at IS NULL WHERE p.deleted_at IS NULL AND s.activity_id = %d AND s.session_date = %s ORDER BY p.last_name, p.first_name';
			return $db->get_results( $db->prepare( $sql, (int) $a['id'], $date ), ARRAY_A ) ?: array();
		}
		$ym  = substr( $date, 0, 7 );
		$sql = "SELECT p.id, p.first_name, p.last_name, p.type, p.card_number FROM $p p JOIN " . Db::t( 'enrollments' ) . ' e ON e.person_id = p.id WHERE p.deleted_at IS NULL AND e.activity_id = %d AND e.start_month <= %s AND (e.end_month IS NULL OR e.end_month >= %s) ORDER BY p.last_name, p.first_name';
		return $db->get_results( $db->prepare( $sql, (int) $a['id'], $ym, $ym ), ARRAY_A ) ?: array();
	}

	/** Segni già registrati per quella data: person_id => presente? Vuoto se la lezione non è ancora stata registrata. @return array<int,bool> */
	public static function marks( int $activity_id, string $date ): array {
		$out = array();
		foreach ( self::db()->get_results( self::db()->prepare( 'SELECT person_id, present FROM ' . Db::t( 'attendance' ) . ' WHERE activity_id = %d AND lesson_date = %s', $activity_id, $date ), ARRAY_A ) ?: array() as $r ) {
			$out[ (int) $r['person_id'] ] = (bool) (int) $r['present'];
		}
		return $out;
	}

	/**
	 * Registra la lezione: chi è in $present_ids risulta presente, gli altri iscritti assenti. @throws \InvalidArgumentException
	 *
	 * @param int[] $present_ids
	 */
	public static function save( int $activity_id, string $date, array $present_ids, ?int $by = null ): int {
		$a = Plugin::activities()->get( $activity_id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		$dt = \DateTime::createFromFormat( 'Y-m-d', $date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
			throw new \InvalidArgumentException( 'Data non valida.' );
		}
		if ( $date > Db::today() ) {
			throw new \InvalidArgumentException( 'Le presenze si registrano a lezione fatta, non in anticipo.' );
		}
		if ( ! self::is_lesson_date( $a, $date ) ) {
			throw new \InvalidArgumentException( 'In quella data l\'attività non ha lezione.' );
		}
		$present = array_flip( array_map( 'intval', $present_ids ) );
		$tbl     = Db::t( 'attendance' );
		self::db()->delete( $tbl, array( 'activity_id' => $activity_id, 'lesson_date' => $date ) );
		$n = 0;
		foreach ( self::roster( $a, $date ) as $r ) {
			$ok = self::db()->insert(
				$tbl,
				array( 'activity_id' => $activity_id, 'lesson_date' => $date, 'person_id' => (int) $r['id'], 'present' => isset( $present[ (int) $r['id'] ] ) ? 1 : 0, 'marked_by' => $by, 'marked_at' => Db::now() )
			);
			$n += $ok ? 1 : 0;
		}
		Audit::log( 'attendance.saved', 'activity', $activity_id, array( 'date' => $date, 'present' => count( array_intersect_key( $present, array_flip( array_column( self::roster( $a, $date ), 'id' ) ) ) ) ) );
		return $n;
	}

	/**
	 * Riepilogo di un'attività nel periodo: lezioni registrate e, per ogni persona, presenze e assenze.
	 *
	 * @return array{dates:string[],people:array[]}
	 */
	public static function summary( int $activity_id, string $from, string $to ): array {
		$db    = self::db();
		$tbl   = Db::t( 'attendance' );
		$dates = $db->get_col( $db->prepare( "SELECT DISTINCT lesson_date FROM $tbl WHERE activity_id = %d AND lesson_date BETWEEN %s AND %s ORDER BY lesson_date", $activity_id, $from, $to ) ) ?: array();
		$rows  = $db->get_results(
			$db->prepare(
				'SELECT a.person_id, p.first_name, p.last_name, p.card_number, SUM(a.present) AS presenze, COUNT(*) AS totale '
				. "FROM $tbl a JOIN " . Db::t( 'people' ) . ' p ON p.id = a.person_id WHERE a.activity_id = %d AND a.lesson_date BETWEEN %s AND %s GROUP BY a.person_id, p.first_name, p.last_name, p.card_number ORDER BY p.last_name, p.first_name',
				$activity_id,
				$from,
				$to
			),
			ARRAY_A
		) ?: array();
		$by_date = array();
		foreach ( $db->get_results( $db->prepare( "SELECT person_id, lesson_date, present FROM $tbl WHERE activity_id = %d AND lesson_date BETWEEN %s AND %s", $activity_id, $from, $to ), ARRAY_A ) ?: array() as $r ) {
			$by_date[ (int) $r['person_id'] ][ $r['lesson_date'] ] = (int) $r['present'];
		}
		foreach ( $rows as &$r ) {
			$r['presenze'] = (int) $r['presenze'];
			$r['totale']   = (int) $r['totale'];
			$r['by_date']  = $by_date[ (int) $r['person_id'] ] ?? array();
		}
		unset( $r );
		return array( 'dates' => array_map( 'strval', $dates ), 'people' => $rows );
	}
}
