<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Attività (corsi), iscrizioni e situazione pagamenti.
 * Le attività sono tenute solo da "soci e volontari"; vi partecipano soci e ospiti.
 */
class ActivityService {

	private function db(): \wpdb {
		return Db::db();
	}

	public function get( int $id ): ?array {
		$row = $this->db()->get_row( $this->db()->prepare( 'SELECT * FROM ' . Db::t( 'activities' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $row ?: null;
	}

	/** Attività di un anno sociale (etichetta "2025/2026"), con nome dell'istruttore. */
	public function for_year( string $label ): array {
		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT a.*, CONCAT(i.first_name, " ", i.last_name) AS instructor_name FROM ' . Db::t( 'activities' ) . ' a '
				. 'LEFT JOIN ' . Db::t( 'people' ) . ' i ON i.id = a.instructor_person_id '
				. 'WHERE a.social_year = %s AND a.deleted_at IS NULL ORDER BY a.name',
				$label
			),
			ARRAY_A
		) ?: array();
	}

	public function create( array $in ): int {
		$d = $this->normalize( $in );
		$this->validate( $d );
		$this->db()->insert(
			Db::t( 'activities' ),
			array(
				'name'                 => $d['name'],
				'social_year'          => $d['social_year'],
				'instructor_person_id' => $d['instructor_person_id'],
				'monthly_fee_cents'    => $d['monthly_fee_cents'],
				'notes'                => $d['notes'],
				'created_at'           => Db::now(),
			)
		);
		$id = (int) $this->db()->insert_id;
		Audit::log( 'activity.created', 'activity', $id, array( 'name' => $d['name'] ) );
		return $id;
	}

	public function update( int $id, array $in ): void {
		$current = $this->get( $id );
		if ( ! $current ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		$d = $this->normalize( array_merge( $current, $in ) );
		$this->validate( $d );
		$this->db()->update(
			Db::t( 'activities' ),
			array(
				'name'                 => $d['name'],
				'instructor_person_id' => $d['instructor_person_id'],
				'monthly_fee_cents'    => $d['monthly_fee_cents'],
				'notes'                => $d['notes'],
			),
			array( 'id' => $id )
		);
		Audit::log( 'activity.updated', 'activity', $id );
	}

	private function normalize( array $in ): array {
		return array(
			'name'                 => trim( (string) ( $in['name'] ?? '' ) ),
			'social_year'          => trim( (string) ( $in['social_year'] ?? '' ) ),
			'instructor_person_id' => ! empty( $in['instructor_person_id'] ) ? (int) $in['instructor_person_id'] : null,
			'monthly_fee_cents'    => (int) ( $in['monthly_fee_cents'] ?? 0 ),
			'notes'                => isset( $in['notes'] ) && '' !== trim( (string) $in['notes'] ) ? trim( (string) $in['notes'] ) : null,
		);
	}

	private function validate( array $d ): void {
		$instructor = $d['instructor_person_id'] ? Plugin::people()->get( $d['instructor_person_id'] ) : null;
		$errors     = Rules::validate_activity( $d, $instructor );
		if ( '' === $d['social_year'] ) {
			$errors[] = 'Anno sociale mancante.';
		}
		if ( $errors ) {
			throw new \InvalidArgumentException( implode( ' ', $errors ) );
		}
	}

	// ---------- Iscrizioni ----------

	private function assert_month( string $m ): void {
		if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $m ) ) {
			throw new \InvalidArgumentException( 'Mese non valido: ' . $m );
		}
	}

	/** Iscrive (o riattiva) una persona: le mensilità sono dovute da $start_month. */
	public function enroll( int $activity_id, int $person_id, string $start_month ): void {
		$this->assert_month( $start_month );
		if ( ! $this->get( $activity_id ) ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		if ( ! Plugin::people()->get( $person_id ) ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		$tbl = Db::t( 'enrollments' );
		$row = $this->db()->get_row( $this->db()->prepare( "SELECT id FROM $tbl WHERE activity_id = %d AND person_id = %d", $activity_id, $person_id ), ARRAY_A );
		if ( $row ) {
			$this->db()->update( $tbl, array( 'start_month' => $start_month, 'end_month' => null ), array( 'id' => (int) $row['id'] ) );
		} else {
			$this->db()->insert( $tbl, array( 'activity_id' => $activity_id, 'person_id' => $person_id, 'start_month' => $start_month, 'created_at' => Db::now() ) );
		}
		Audit::log( 'activity.enrolled', 'activity', $activity_id, array( 'person_id' => $person_id, 'from' => $start_month ) );
	}

	/** Cancella dall'attività: $last_month è l'ultimo mese ancora dovuto. I pagamenti restano registrati. */
	public function cancel( int $activity_id, int $person_id, string $last_month ): void {
		$this->assert_month( $last_month );
		$this->db()->update( Db::t( 'enrollments' ), array( 'end_month' => $last_month ), array( 'activity_id' => $activity_id, 'person_id' => $person_id ) );
		Audit::log( 'activity.unenrolled', 'activity', $activity_id, array( 'person_id' => $person_id, 'last_month' => $last_month ) );
	}

	public function active_activity_ids( int $person_id ): array {
		return array_map(
			'intval',
			$this->db()->get_col( $this->db()->prepare( 'SELECT activity_id FROM ' . Db::t( 'enrollments' ) . ' WHERE person_id = %d AND end_month IS NULL', $person_id ) )
		);
	}

	public function active_participants( int $activity_id ): int {
		return (int) $this->db()->get_var( $this->db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND end_month IS NULL', $activity_id ) );
	}

	// ---------- Situazione pagamenti ----------

	/** @return array ['activity_id' => ['person_id' => ['YYYY-MM' => centesimi]]] */
	private function paid_map( array $activity_ids ): array {
		if ( ! $activity_ids ) {
			return array();
		}
		$ids  = implode( ',', array_map( 'intval', $activity_ids ) );
		$rows = $this->db()->get_results(
			"SELECT activity_id, person_id, COALESCE(competence_month, DATE_FORMAT(tx_date, '%Y-%m')) AS ym, SUM(amount_cents) AS s "
			. 'FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND person_id IS NOT NULL AND activity_id IN ($ids) "
			. 'GROUP BY activity_id, person_id, ym',
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['activity_id'] ][ (int) $r['person_id'] ][ $r['ym'] ] = (int) $r['s'];
		}
		return $out;
	}

	private function summarize( array $activity, array $enrollment, array $paid_by_month ): array {
		return PaymentCalc::compute(
			(int) $activity['monthly_fee_cents'],
			$enrollment['start_month'],
			$enrollment['end_month'],
			substr( Db::today(), 0, 7 ),
			SocialYear::from_label( $activity['social_year'], Settings::start_month() ),
			$paid_by_month
		);
	}

	/** Iscritti (attivi e cancellati) di un'attività con situazione pagamenti, attivi per primi. */
	public function status_for_activity( int $activity_id ): array {
		$activity = $this->get( $activity_id );
		if ( ! $activity ) {
			return array();
		}
		$rows = $this->db()->get_results(
			$this->db()->prepare(
				'SELECT e.*, p.first_name, p.last_name, p.type, p.card_number, p.email, p.host_person_id FROM ' . Db::t( 'enrollments' ) . ' e '
				. 'JOIN ' . Db::t( 'people' ) . ' p ON p.id = e.person_id AND p.deleted_at IS NULL WHERE e.activity_id = %d',
				$activity_id
			),
			ARRAY_A
		) ?: array();
		$paid = $this->paid_map( array( $activity_id ) );
		$out  = array();
		foreach ( $rows as $e ) {
			$out[] = array(
				'enrollment' => $e,
				'activity'   => $activity,
				'summary'    => $this->summarize( $activity, $e, $paid[ $activity_id ][ (int) $e['person_id'] ] ?? array() ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				$act = ( null === $a['enrollment']['end_month'] ? 0 : 1 ) <=> ( null === $b['enrollment']['end_month'] ? 0 : 1 );
				return $act ?: strcmp( Text::lower( $a['enrollment']['last_name'] . $a['enrollment']['first_name'] ), Text::lower( $b['enrollment']['last_name'] . $b['enrollment']['first_name'] ) );
			}
		);
		return $out;
	}

	/** Attività di una persona con la sua situazione pagamenti, dalla più recente. */
	public function status_for_person( int $person_id ): array {
		$rows = $this->db()->get_results(
			$this->db()->prepare(
				'SELECT e.*, a.name AS activity_name, a.social_year, a.monthly_fee_cents FROM ' . Db::t( 'enrollments' ) . ' e '
				. 'JOIN ' . Db::t( 'activities' ) . ' a ON a.id = e.activity_id AND a.deleted_at IS NULL WHERE e.person_id = %d ORDER BY a.social_year DESC, a.name',
				$person_id
			),
			ARRAY_A
		) ?: array();
		$paid = $this->paid_map( array_column( $rows, 'activity_id' ) );
		$out  = array();
		foreach ( $rows as $e ) {
			$activity = array( 'id' => $e['activity_id'], 'name' => $e['activity_name'], 'social_year' => $e['social_year'], 'monthly_fee_cents' => $e['monthly_fee_cents'] );
			$out[]    = array(
				'enrollment' => $e,
				'activity'   => $activity,
				'summary'    => $this->summarize( $activity, $e, $paid[ (int) $e['activity_id'] ][ $person_id ] ?? array() ),
			);
		}
		return $out;
	}

	/** Primo mese dovuto e non (del tutto) pagato: serve a proporlo in fase di incasso. */
	public function first_unpaid_month( int $activity_id, int $person_id ): ?string {
		foreach ( $this->status_for_activity( $activity_id ) as $s ) {
			if ( (int) $s['enrollment']['person_id'] === $person_id && null === $s['enrollment']['end_month'] ) {
				return $s['summary']['unpaid_months'][0]['month'] ?? null;
			}
		}
		return null;
	}
}
