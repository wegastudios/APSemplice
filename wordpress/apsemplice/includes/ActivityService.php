<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Attività, iscrizioni/prenotazioni e situazione pagamenti.
 *
 * Tre tipi (vedi {@see ActivityKind}), tutti gratuiti o con contributo, con contributo ospiti eventualmente diverso:
 *  - corso: iscrizione per mesi (`enrollments`), contributo mensile;
 *  - evento una tantum: una data (`sessions`), prenotazione obbligatoria (`bookings`), un contributo;
 *  - evento ricorrente: molte date, prenotazione obbligatoria al singolo evento, contributo per evento.
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

	/** Contributo dovuto da una persona per questa attività (ospiti: contributo ospiti se impostato). */
	public function fee_for( array $activity, string $person_type ): int {
		return Pricing::fee_for(
			(int) $activity['fee_cents'],
			null === $activity['guest_fee_cents'] || '' === $activity['guest_fee_cents'] ? null : (int) $activity['guest_fee_cents'],
			$person_type
		);
	}

	// ---------- Attività ----------

	/**
	 * @param array $in name, social_year, kind, instructor_person_id, fee_cents (o monthly_fee_cents), guest_fee_cents (null = come i soci), notes
	 *                  e, per l'evento una tantum, session: [session_date, start_time?, location?, capacity?]
	 */
	public function create( array $in ): int {
		$in = $this->with_fee_alias( $in );
		$d  = $this->normalize( $in );
		$this->validate( $d );
		$session = null;
		if ( ActivityKind::EVENT === $d['kind'] ) {
			$session = $this->normalize_session( (array) ( $in['session'] ?? array() ) );
			$this->validate_session( $session );
		}
		$this->db()->insert(
			Db::t( 'activities' ),
			array(
				'name'                 => $d['name'],
				'social_year'          => $d['social_year'],
				'kind'                 => $d['kind'],
				'instructor_person_id' => $d['instructor_person_id'],
				'fee_cents'            => $d['fee_cents'],
				'guest_fee_cents'      => $d['guest_fee_cents'],
				'cancellable'          => ActivityKind::uses_sessions( $d['kind'] ) ? $d['cancellable'] : 0,
				'cancel_policy'        => ActivityKind::uses_sessions( $d['kind'] ) ? $d['cancel_policy'] : null,
				'booking_qr'           => ActivityKind::uses_sessions( $d['kind'] ) ? $d['booking_qr'] : 0,
				'notes'                => $d['notes'],
				'created_at'           => Db::now(),
			)
		);
		$id = (int) $this->db()->insert_id;
		if ( $session ) {
			$this->insert_session( $id, $session );
		}
		Audit::log( 'activity.created', 'activity', $id, array( 'name' => $d['name'], 'kind' => $d['kind'] ) );
		return $id;
	}

	public function update( int $id, array $in ): void {
		$current = $this->get( $id );
		if ( ! $current ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		unset( $current['monthly_fee_cents'] );
		$d = $this->normalize( array_merge( $current, $this->with_fee_alias( $in ) ) );
		$d['kind'] = $current['kind']; // il tipo non si cambia dopo la creazione
		$this->validate( $d );
		$this->db()->update(
			Db::t( 'activities' ),
			array(
				'name'                 => $d['name'],
				'instructor_person_id' => $d['instructor_person_id'],
				'fee_cents'            => $d['fee_cents'],
				'guest_fee_cents'      => $d['guest_fee_cents'],
				'cancellable'          => ActivityKind::uses_sessions( $current['kind'] ) ? $d['cancellable'] : 0,
				'cancel_policy'        => ActivityKind::uses_sessions( $current['kind'] ) ? $d['cancel_policy'] : null,
				'booking_qr'           => ActivityKind::uses_sessions( $current['kind'] ) ? $d['booking_qr'] : 0,
				'notes'                => $d['notes'],
			),
			array( 'id' => $id )
		);
		Audit::log( 'activity.updated', 'activity', $id );
	}

	/** Compatibilità: `monthly_fee_cents` era il nome del contributo prima dei tipi di attività. */
	private function with_fee_alias( array $in ): array {
		if ( isset( $in['monthly_fee_cents'] ) && ! isset( $in['fee_cents'] ) ) {
			$in['fee_cents'] = $in['monthly_fee_cents'];
		}
		unset( $in['monthly_fee_cents'] );
		return $in;
	}

	private function normalize( array $in ): array {
		$guest = null;
		if ( array_key_exists( 'guest_fee_cents', $in ) && null !== $in['guest_fee_cents'] && '' !== $in['guest_fee_cents'] ) {
			$guest = (int) $in['guest_fee_cents'];
		}
		return array(
			'name'                 => trim( (string) ( $in['name'] ?? '' ) ),
			'social_year'          => trim( (string) ( $in['social_year'] ?? '' ) ),
			'kind'                 => (string) ( $in['kind'] ?? ActivityKind::COURSE ),
			'instructor_person_id' => ! empty( $in['instructor_person_id'] ) ? (int) $in['instructor_person_id'] : null,
			'fee_cents'            => (int) ( $in['fee_cents'] ?? 0 ),
			'guest_fee_cents'      => $guest,
			'cancellable'          => ! empty( $in['cancellable'] ) ? 1 : 0,
			'cancel_policy'        => isset( $in['cancel_policy'] ) && CancelPolicy::is_valid( (string) $in['cancel_policy'] ) ? (string) $in['cancel_policy'] : null,
			'booking_qr'           => ! empty( $in['booking_qr'] ) ? 1 : 0,
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

	// ---------- Date (eventi) ----------

	private function normalize_session( array $s ): array {
		$cap = isset( $s['capacity'] ) && '' !== $s['capacity'] && null !== $s['capacity'] ? (int) $s['capacity'] : null;
		$t   = function ( $k ) use ( $s ) {
			$v = isset( $s[ $k ] ) ? trim( (string) $s[ $k ] ) : '';
			return '' === $v ? null : $v;
		};
		return array(
			'session_date' => (string) ( $s['session_date'] ?? '' ),
			'start_time'   => $t( 'start_time' ),
			'location'     => $t( 'location' ),
			'capacity'     => $cap,
			'notes'        => $t( 'notes' ),
		);
	}

	private function validate_session( array $s ): void {
		$errors = Rules::validate_session( $s );
		if ( $errors ) {
			throw new \InvalidArgumentException( implode( ' ', $errors ) );
		}
	}

	private function insert_session( int $activity_id, array $s ): int {
		$this->db()->insert( Db::t( 'sessions' ), array_merge( $s, array( 'activity_id' => $activity_id, 'created_at' => Db::now() ) ) );
		$id = (int) $this->db()->insert_id;
		Audit::log( 'session.created', 'activity', $activity_id, array( 'session' => $id, 'date' => $s['session_date'] ) );
		return $id;
	}

	public function session( int $id ): ?array {
		$row = $this->db()->get_row( $this->db()->prepare( 'SELECT * FROM ' . Db::t( 'sessions' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	/** Date di un'attività, in ordine, con il numero di prenotazioni attive. */
	public function sessions( int $activity_id ): array {
		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT s.*, (SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " b WHERE b.session_id = s.id AND b.status = 'booked') AS booked_count "
				. 'FROM ' . Db::t( 'sessions' ) . ' s WHERE s.activity_id = %d ORDER BY s.session_date, s.start_time, s.id',
				$activity_id
			),
			ARRAY_A
		) ?: array();
	}

	private function assert_uses_sessions( ?array $activity ): array {
		if ( ! $activity ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		if ( ! ActivityKind::uses_sessions( $activity['kind'] ) ) {
			throw new \InvalidArgumentException( 'I corsi non hanno date: si iscrivono per mesi.' );
		}
		return $activity;
	}

	public function add_session( int $activity_id, array $s ): int {
		$a = $this->assert_uses_sessions( $this->get( $activity_id ) );
		if ( ActivityKind::EVENT === $a['kind'] && $this->sessions( $activity_id ) ) {
			throw new \InvalidArgumentException( 'Un evento una tantum ha una sola data: modifica quella esistente.' );
		}
		$n = $this->normalize_session( $s );
		$this->validate_session( $n );
		return $this->insert_session( $activity_id, $n );
	}

	public function update_session( int $session_id, array $s ): void {
		$cur = $this->session( $session_id );
		if ( ! $cur ) {
			throw new \InvalidArgumentException( 'Data non trovata.' );
		}
		$n = $this->normalize_session( array_merge( $cur, $s ) );
		$this->validate_session( $n );
		$this->db()->update( Db::t( 'sessions' ), $n, array( 'id' => $session_id ) );
		Audit::log( 'session.updated', 'activity', (int) $cur['activity_id'], array( 'session' => $session_id ) );
	}

	/** Crea una data ogni 7 giorni da $from a $to compresi (max 120). @return int date create */
	public function generate_weekly( int $activity_id, string $from, string $to, ?string $time = null, ?string $location = null, ?int $capacity = null ): int {
		$a = $this->assert_uses_sessions( $this->get( $activity_id ) );
		if ( ActivityKind::RECURRING !== $a['kind'] ) {
			throw new \InvalidArgumentException( 'La ricorrenza vale solo per gli eventi ricorrenti.' );
		}
		$base = array( 'start_time' => $time, 'location' => $location, 'capacity' => $capacity );
		$this->validate_session( $this->normalize_session( array_merge( $base, array( 'session_date' => $from ) ) ) );
		$this->validate_session( $this->normalize_session( array_merge( $base, array( 'session_date' => $to ) ) ) );
		$d   = new \DateTimeImmutable( $from );
		$end = new \DateTimeImmutable( $to );
		if ( $end < $d ) {
			throw new \InvalidArgumentException( 'La data finale è prima di quella iniziale.' );
		}
		$existing = array_column( $this->sessions( $activity_id ), 'session_date' );
		$made     = 0;
		while ( $d <= $end ) {
			if ( $made >= 120 ) {
				throw new \InvalidArgumentException( 'Troppe date in una volta (massimo 120): accorcia il periodo.' );
			}
			$date = $d->format( 'Y-m-d' );
			if ( ! in_array( $date, $existing, true ) ) {
				$this->insert_session( $activity_id, $this->normalize_session( array_merge( $base, array( 'session_date' => $date ) ) ) );
				$made++;
			}
			$d = $d->modify( '+7 days' );
		}
		return $made;
	}

	/** Annulla una data. Le prenotazioni restano ma non contano più; eventuali pagamenti vanno rimborsati a mano. */
	public function cancel_session( int $session_id ): void {
		$s = $this->session( $session_id );
		if ( ! $s ) {
			throw new \InvalidArgumentException( 'Data non trovata.' );
		}
		$this->db()->update( Db::t( 'sessions' ), array( 'cancelled_at' => Db::now() ), array( 'id' => $session_id ) );
		Audit::log( 'session.cancelled', 'activity', (int) $s['activity_id'], array( 'session' => $session_id ) );
	}

	// ---------- Prenotazioni ----------

	/** Prenota una persona a una data. Il contributo dovuto è fissato adesso (socio o ospite). */
	public function book( int $session_id, int $person_id ): int {
		$s = $this->session( $session_id );
		if ( ! $s || $s['cancelled_at'] ) {
			throw new \InvalidArgumentException( 'Data non disponibile (inesistente o annullata).' );
		}
		$a      = $this->get( (int) $s['activity_id'] );
		$person = Plugin::people()->get( $person_id );
		if ( ! $a || ! $person ) {
			throw new \InvalidArgumentException( 'Attività o persona non trovata.' );
		}
		$tbl      = Db::t( 'bookings' );
		$existing = $this->db()->get_row( $this->db()->prepare( "SELECT * FROM $tbl WHERE session_id = %d AND person_id = %d", $session_id, $person_id ), ARRAY_A );
		if ( $existing && 'booked' === $existing['status'] ) {
			throw new \InvalidArgumentException( 'Questa persona è già prenotata a questa data.' );
		}
		if ( null !== $s['capacity'] ) {
			$taken = (int) $this->db()->get_var( $this->db()->prepare( "SELECT COUNT(*) FROM $tbl WHERE session_id = %d AND status = 'booked'", $session_id ) );
			if ( $taken >= (int) $s['capacity'] ) {
				throw new \InvalidArgumentException( 'Posti esauriti per questa data (' . (int) $s['capacity'] . ').' );
			}
		}
		$fee = $this->fee_for( $a, $person['type'] );
		if ( $existing ) {
			$this->db()->update( $tbl, array( 'status' => 'booked', 'fee_due_cents' => $fee, 'cancelled_at' => null, 'transferred_to' => null, 'transferred_from' => null ), array( 'id' => (int) $existing['id'] ) );
			$id = (int) $existing['id'];
		} else {
			$this->db()->insert( $tbl, array( 'session_id' => $session_id, 'person_id' => $person_id, 'status' => 'booked', 'fee_due_cents' => $fee, 'created_at' => Db::now() ) );
			$id = (int) $this->db()->insert_id;
		}
		Audit::log( 'booking.created', 'activity', (int) $a['id'], array( 'session' => $session_id, 'person_id' => $person_id, 'fee' => $fee ) );
		return $id;
	}

	/** Annulla la prenotazione. Gli eventuali pagamenti restano registrati (rimborso a mano). */
	public function cancel_booking( int $session_id, int $person_id ): void {
		$s = $this->session( $session_id );
		$this->db()->update( Db::t( 'bookings' ), array( 'status' => 'cancelled', 'cancelled_at' => Db::now() ), array( 'session_id' => $session_id, 'person_id' => $person_id ) );
		Audit::log( 'booking.cancelled', 'activity', $s ? (int) $s['activity_id'] : null, array( 'session' => $session_id, 'person_id' => $person_id ) );
	}

	private function booking_row( array $b, int $paid ): array {
		return array_merge(
			$b,
			array(
				'paid'      => $paid,
				'state'     => Pricing::booking_state( (int) $b['fee_due_cents'], $paid ),
				'remaining' => Pricing::remaining( (int) $b['fee_due_cents'], $paid ),
				'active'    => 'booked' === $b['status'],
			)
		);
	}

	/** Prenotazioni di una data con situazione pagamenti, attive per prime. */
	public function bookings_for_session( int $session_id ): array {
		$rows = $this->db()->get_results(
			$this->db()->prepare(
				'SELECT b.*, p.first_name, p.last_name, p.type, p.card_number, p.email FROM ' . Db::t( 'bookings' ) . ' b '
				. 'JOIN ' . Db::t( 'people' ) . ' p ON p.id = b.person_id AND p.deleted_at IS NULL WHERE b.session_id = %d '
				. "ORDER BY (b.status = 'booked') DESC, p.last_name, p.first_name",
				$session_id
			),
			ARRAY_A
		) ?: array();
		$paid = array();
		foreach ( $this->db()->get_results(
			$this->db()->prepare( 'SELECT person_id, SUM(amount_cents) AS s FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND session_id = %d GROUP BY person_id", $session_id ),
			ARRAY_A
		) ?: array() as $r ) {
			$paid[ (int) $r['person_id'] ] = (int) $r['s'];
		}
		return array_map(
			function ( $b ) use ( $paid ) {
				return $this->booking_row( $b, $paid[ (int) $b['person_id'] ] ?? 0 );
			},
			$rows
		);
	}

	/** Prenotazioni di una persona (con attività e data), dalla più recente. */
	public function bookings_for_person( int $person_id ): array {
		$rows = $this->db()->get_results(
			$this->db()->prepare(
				'SELECT b.*, s.session_date, s.start_time, s.location, s.cancelled_at AS session_cancelled_at, s.activity_id, a.name AS activity_name, a.kind, a.booking_qr '
				. 'FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id '
				. 'JOIN ' . Db::t( 'activities' ) . ' a ON a.id = s.activity_id AND a.deleted_at IS NULL WHERE b.person_id = %d ORDER BY s.session_date DESC, s.id DESC',
				$person_id
			),
			ARRAY_A
		) ?: array();
		$paid = array();
		foreach ( $this->db()->get_results(
			$this->db()->prepare( 'SELECT session_id, SUM(amount_cents) AS s FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND person_id = %d AND session_id IS NOT NULL GROUP BY session_id", $person_id ),
			ARRAY_A
		) ?: array() as $r ) {
			$paid[ (int) $r['session_id'] ] = (int) $r['s'];
		}
		return array_map(
			function ( $b ) use ( $paid ) {
				$row           = $this->booking_row( $b, $paid[ (int) $b['session_id'] ] ?? 0 );
				$row['active'] = $row['active'] && empty( $b['session_cancelled_at'] );
				return $row;
			},
			$rows
		);
	}

	/** Prenotazioni attive con contributo ancora da pagare (per proporle in fase di incasso). */
	public function unpaid_bookings_for_person( int $person_id ): array {
		return array_values(
			array_filter(
				$this->bookings_for_person( $person_id ),
				function ( $b ) {
					return $b['active'] && $b['remaining'] > 0;
				}
			)
		);
	}

	/** True se la persona ha una prenotazione attiva per quella data. */
	public function has_active_booking( int $session_id, int $person_id ): bool {
		return (bool) $this->db()->get_var(
			$this->db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " WHERE session_id = %d AND person_id = %d AND status = 'booked'", $session_id, $person_id )
		);
	}

	/** Persone distinte con una prenotazione attiva a una data non annullata di questa attività. */
	public function booked_people( int $activity_id ): array {
		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT DISTINCT p.id AS person_id, p.first_name, p.last_name, p.type, p.email FROM ' . Db::t( 'bookings' ) . ' b '
				. 'JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id AND s.cancelled_at IS NULL '
				. 'JOIN ' . Db::t( 'people' ) . " p ON p.id = b.person_id AND p.deleted_at IS NULL WHERE s.activity_id = %d AND b.status = 'booked' ORDER BY p.last_name, p.first_name",
				$activity_id
			),
			ARRAY_A
		) ?: array();
	}

	// ---------- Cancellazione e cambio di nominativo ----------

	/** Riga di prenotazione grezza (anche annullata o trasferita). */
	public function booking( int $session_id, int $person_id ): ?array {
		$row = $this->db()->get_row(
			$this->db()->prepare( 'SELECT * FROM ' . Db::t( 'bookings' ) . ' WHERE session_id = %d AND person_id = %d', $session_id, $person_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Cosa può fare il socio con una prenotazione attiva: annullarla (regole di {@see CancelPolicy}) e/o cambiare nominativo.
	 *
	 * @return array ['allowed'=>bool, 'reason'=>string, 'deadline'=>?\DateTimeImmutable, 'message'=>string, 'can_transfer'=>bool]
	 */
	public function cancellation_for( int $session_id, int $person_id ): array {
		$b = $this->booking( $session_id, $person_id );
		$s = $this->session( $session_id );
		$a = $s ? $this->get( (int) $s['activity_id'] ) : null;
		if ( ! $b || 'booked' !== $b['status'] || ! $s || ! $a ) {
			return array( 'allowed' => false, 'reason' => 'none', 'deadline' => null, 'message' => 'Prenotazione non trovata.', 'can_transfer' => false );
		}
		$now  = current_datetime();
		$eval = CancelPolicy::evaluate(
			(int) $b['fee_due_cents'],
			! empty( $a['cancellable'] ),
			$a['cancel_policy'] ?: null,
			(string) Settings::get( 'cancel_policy_default' ),
			$s['session_date'],
			$s['start_time'] ?: null,
			$now
		);
		$eval['message']      = CancelPolicy::message( $eval );
		$eval['can_transfer'] = CancelPolicy::can_transfer( $s['session_date'], $s['start_time'] ?: null, $now );
		return $eval;
	}

	/**
	 * Cambia nominativo: la prenotazione (e quanto già pagato) passa a un'altra persona. Se il nuovo partecipante deve
	 * un contributo maggiore (es. un ospite) la differenza risulta "da pagare"; se è minore non c'è rimborso.
	 * I pagamenti già registrati vengono intestati al nuovo partecipante (con una nota nella descrizione).
	 *
	 * @param bool $enforce_time true per i soci dal sito (non dopo l'inizio dell'evento); false per l'amministratore
	 */
	public function transfer_booking( int $session_id, int $from_id, int $to_id, bool $enforce_time = true ): void {
		$s = $this->session( $session_id );
		if ( ! $s || $s['cancelled_at'] ) {
			throw new \InvalidArgumentException( 'Data non disponibile (inesistente o annullata).' );
		}
		$a    = $this->get( (int) $s['activity_id'] );
		$from = $this->booking( $session_id, $from_id );
		$to   = Plugin::people()->get( $to_id );
		$from_p = Plugin::people()->get( $from_id );
		if ( ! $a || ! $from || 'booked' !== $from['status'] ) {
			throw new \InvalidArgumentException( 'La prenotazione da cambiare non è attiva.' );
		}
		if ( ! $to || ! $from_p ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		if ( $from_id === $to_id ) {
			throw new \InvalidArgumentException( 'Scegli una persona diversa.' );
		}
		if ( $enforce_time && ! CancelPolicy::can_transfer( $s['session_date'], $s['start_time'] ?: null, current_datetime() ) ) {
			throw new \InvalidArgumentException( 'L\'evento è già iniziato: non si può più cambiare il nominativo.' );
		}
		if ( $this->has_active_booking( $session_id, $to_id ) ) {
			throw new \InvalidArgumentException( $to['first_name'] . ' è già prenotato/a a questa data.' );
		}
		$tbl  = Db::t( 'bookings' );
		$fee  = $this->fee_for( $a, $to['type'] );
		$note = ' [intestato da ' . trim( $from_p['first_name'] . ' ' . $from_p['last_name'] ) . ' a ' . trim( $to['first_name'] . ' ' . $to['last_name'] ) . ']';
		$this->in_transaction(
			function () use ( $tbl, $session_id, $from, $from_id, $to_id, $fee, $note ) {
				$this->db()->update( $tbl, array( 'status' => 'transferred', 'transferred_to' => $to_id, 'cancelled_at' => Db::now() ), array( 'id' => (int) $from['id'] ) );
				$existing = $this->booking( $session_id, $to_id );
				$data     = array( 'status' => 'booked', 'fee_due_cents' => $fee, 'cancelled_at' => null, 'transferred_to' => null, 'transferred_from' => $from_id );
				if ( $existing ) {
					$this->db()->update( $tbl, $data, array( 'id' => (int) $existing['id'] ) );
				} else {
					$this->db()->insert( $tbl, array_merge( $data, array( 'session_id' => $session_id, 'person_id' => $to_id, 'created_at' => Db::now() ) ) );
				}
				$this->db()->query(
					$this->db()->prepare(
						'UPDATE ' . Db::t( 'transactions' ) . " SET person_id = %d, description = LEFT(CONCAT(description, %s), 255) WHERE session_id = %d AND person_id = %d AND type = 'income' AND voided_at IS NULL",
						$to_id,
						$note,
						$session_id,
						$from_id
					)
				);
			}
		);
		Audit::log( 'booking.transferred', 'activity', (int) $a['id'], array( 'session' => $session_id, 'from' => $from_id, 'to' => $to_id, 'fee' => $fee ) );
	}

	private function in_transaction( callable $fn ) {
		$this->db()->query( 'START TRANSACTION' );
		try {
			$res = $fn();
			$this->db()->query( 'COMMIT' );
			return $res;
		} catch ( \Throwable $e ) {
			$this->db()->query( 'ROLLBACK' );
			throw $e;
		}
	}

	// ---------- Corsi: iscrizioni per mese ----------

	private function assert_month( string $m ): void {
		if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $m ) ) {
			throw new \InvalidArgumentException( 'Mese non valido: ' . $m );
		}
	}

	/** Iscrive (o riattiva) una persona a un CORSO: le mensilità sono dovute da $start_month. */
	public function enroll( int $activity_id, int $person_id, string $start_month ): void {
		$this->assert_month( $start_month );
		$a = $this->get( $activity_id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		if ( ActivityKind::COURSE !== $a['kind'] ) {
			throw new \InvalidArgumentException( 'Agli eventi ci si prenota a una data: usa la prenotazione.' );
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

	/** Cancella da un corso: $last_month è l'ultimo mese ancora dovuto. I pagamenti restano registrati. */
	public function cancel( int $activity_id, int $person_id, string $last_month ): void {
		$this->assert_month( $last_month );
		$this->db()->update( Db::t( 'enrollments' ), array( 'end_month' => $last_month ), array( 'activity_id' => $activity_id, 'person_id' => $person_id ) );
		Audit::log( 'activity.unenrolled', 'activity', $activity_id, array( 'person_id' => $person_id, 'last_month' => $last_month ) );
	}

	/** Corsi a cui la persona è iscritta (iscrizione attiva). */
	public function active_activity_ids( int $person_id ): array {
		return array_map(
			'intval',
			$this->db()->get_col( $this->db()->prepare( 'SELECT activity_id FROM ' . Db::t( 'enrollments' ) . ' WHERE person_id = %d AND end_month IS NULL', $person_id ) )
		);
	}

	/** Partecipanti: iscritti attivi per i corsi, persone distinte con una prenotazione attiva per gli eventi. */
	public function active_participants( int $activity_id ): int {
		$a = $this->get( $activity_id );
		if ( $a && ActivityKind::uses_sessions( $a['kind'] ) ) {
			return (int) $this->db()->get_var(
				$this->db()->prepare(
					'SELECT COUNT(DISTINCT b.person_id) FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . " s ON s.id = b.session_id AND s.cancelled_at IS NULL WHERE s.activity_id = %d AND b.status = 'booked'",
					$activity_id
				)
			);
		}
		return (int) $this->db()->get_var( $this->db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND end_month IS NULL', $activity_id ) );
	}

	// ---------- Corsi: situazione pagamenti ----------

	/** @return array ['activity_id' => ['person_id' => ['YYYY-MM' => centesimi]]] (solo incassi senza data di evento) */
	private function paid_map( array $activity_ids ): array {
		if ( ! $activity_ids ) {
			return array();
		}
		$ids  = implode( ',', array_map( 'intval', $activity_ids ) );
		$rows = $this->db()->get_results(
			"SELECT activity_id, person_id, COALESCE(competence_month, DATE_FORMAT(tx_date, '%Y-%m')) AS ym, SUM(amount_cents) AS s "
			. 'FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND session_id IS NULL AND person_id IS NOT NULL AND activity_id IN ($ids) "
			. 'GROUP BY activity_id, person_id, ym',
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['activity_id'] ][ (int) $r['person_id'] ][ $r['ym'] ] = (int) $r['s'];
		}
		return $out;
	}

	private function summarize( array $activity, array $enrollment, array $paid_by_month, string $person_type ): array {
		return PaymentCalc::compute(
			$this->fee_for( $activity, $person_type ),
			$enrollment['start_month'],
			$enrollment['end_month'],
			substr( Db::today(), 0, 7 ),
			SocialYear::from_label( $activity['social_year'], Settings::start_month() ),
			$paid_by_month
		);
	}

	/** Iscritti (attivi e cancellati) di un corso con situazione pagamenti, attivi per primi. */
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
				'summary'    => $this->summarize( $activity, $e, $paid[ $activity_id ][ (int) $e['person_id'] ] ?? array(), $e['type'] ),
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

	/** Corsi di una persona con la sua situazione pagamenti, dal più recente. */
	public function status_for_person( int $person_id ): array {
		$person = Plugin::people()->get( $person_id );
		if ( ! $person ) {
			return array();
		}
		$rows = $this->db()->get_results(
			$this->db()->prepare(
				'SELECT e.*, a.name AS activity_name, a.social_year, a.kind, a.fee_cents, a.guest_fee_cents FROM ' . Db::t( 'enrollments' ) . ' e '
				. 'JOIN ' . Db::t( 'activities' ) . ' a ON a.id = e.activity_id AND a.deleted_at IS NULL WHERE e.person_id = %d ORDER BY a.social_year DESC, a.name',
				$person_id
			),
			ARRAY_A
		) ?: array();
		$paid = $this->paid_map( array_column( $rows, 'activity_id' ) );
		$out  = array();
		foreach ( $rows as $e ) {
			$activity = array(
				'id' => $e['activity_id'], 'name' => $e['activity_name'], 'social_year' => $e['social_year'], 'kind' => $e['kind'],
				'fee_cents' => $e['fee_cents'], 'guest_fee_cents' => $e['guest_fee_cents'],
			);
			$out[]    = array(
				'enrollment' => $e,
				'activity'   => $activity,
				'summary'    => $this->summarize( $activity, $e, $paid[ (int) $e['activity_id'] ][ $person_id ] ?? array(), $person['type'] ),
			);
		}
		return $out;
	}

	/** Primo mese dovuto e non (del tutto) pagato di un corso: serve a proporlo in fase di incasso. */
	public function first_unpaid_month( int $activity_id, int $person_id ): ?string {
		foreach ( $this->status_for_activity( $activity_id ) as $s ) {
			if ( (int) $s['enrollment']['person_id'] === $person_id && null === $s['enrollment']['end_month'] ) {
				return $s['summary']['unpaid_months'][0]['month'] ?? null;
			}
		}
		return null;
	}

	// ---------- Per l'area riservata e i contenuti riservati ----------

	/** Attività a cui la persona partecipa: corsi con iscrizione non conclusa, eventi con una prenotazione attiva. */
	public function person_activity_ids( int $person_id ): array {
		$ym      = substr( Db::today(), 0, 7 );
		$courses = $this->db()->get_col(
			$this->db()->prepare( 'SELECT activity_id FROM ' . Db::t( 'enrollments' ) . ' WHERE person_id = %d AND (end_month IS NULL OR end_month >= %s)', $person_id, $ym )
		);
		$events  = $this->db()->get_col(
			$this->db()->prepare(
				'SELECT DISTINCT s.activity_id FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . " s ON s.id = b.session_id AND s.cancelled_at IS NULL WHERE b.person_id = %d AND b.status = 'booked'",
				$person_id
			)
		);
		return array_values( array_unique( array_map( 'intval', array_merge( $courses, $events ) ) ) );
	}

	/** Attività tenute da una persona (istruttore). */
	public function taught_activity_ids( int $person_id ): array {
		return array_map(
			'intval',
			$this->db()->get_col( $this->db()->prepare( 'SELECT id FROM ' . Db::t( 'activities' ) . ' WHERE instructor_person_id = %d AND deleted_at IS NULL', $person_id ) )
		);
	}

	/** Tutte le attività (per le liste di scelta), dalle più recenti. */
	public function all_for_select(): array {
		return $this->db()->get_results( 'SELECT id, name, social_year, kind FROM ' . Db::t( 'activities' ) . ' WHERE deleted_at IS NULL ORDER BY social_year DESC, name', ARRAY_A ) ?: array();
	}

	/** Prossime date non annullate (da oggi), con i dati dell'attività e i posti occupati. */
	public function upcoming_sessions( int $limit = 10, ?int $activity_id = null ): array {
		$sql  = 'SELECT s.*, a.name AS activity_name, a.kind, a.fee_cents, a.guest_fee_cents, '
			. '(SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " b WHERE b.session_id = s.id AND b.status = 'booked') AS booked_count "
			. 'FROM ' . Db::t( 'sessions' ) . ' s JOIN ' . Db::t( 'activities' ) . ' a ON a.id = s.activity_id AND a.deleted_at IS NULL '
			. 'WHERE s.cancelled_at IS NULL AND s.session_date >= %s';
		$args = array( Db::today() );
		if ( $activity_id ) {
			$sql   .= ' AND a.id = %d';
			$args[] = $activity_id;
		}
		$sql   .= ' ORDER BY s.session_date, s.start_time, s.id LIMIT %d';
		$args[] = max( 1, $limit );
		return $this->db()->get_results( $this->db()->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}
}
