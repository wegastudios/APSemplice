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

	/** Attività di un anno sociale (etichetta "2025/2026"), con nome del referente. */
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
				'vat_rate'             => $d['vat_rate'],
				'cancellable'          => ActivityKind::uses_sessions( $d['kind'] ) ? $d['cancellable'] : 0,
				'cancel_policy'        => ActivityKind::uses_sessions( $d['kind'] ) ? $d['cancel_policy'] : null,
				'booking_qr'           => ActivityKind::uses_sessions( $d['kind'] ) ? $d['booking_qr'] : 0,
				'lesson_weekday'       => ActivityKind::COURSE === $d['kind'] ? $d['lesson_weekday'] : 0,
				'billing'              => ActivityKind::COURSE === $d['kind'] ? $d['billing'] : 'monthly',
				'lesson_start'        => ActivityKind::COURSE === $d['kind'] ? $d['lesson_start'] : null,
				'lesson_slots'        => ActivityKind::COURSE === $d['kind'] ? $d['lesson_slots'] : null,
				'lesson_end'          => ActivityKind::COURSE === $d['kind'] ? $d['lesson_end'] : null,
				'location'            => ActivityKind::COURSE === $d['kind'] ? $d['location'] : null,
				'starts_on'           => ActivityKind::COURSE === $d['kind'] ? $d['starts_on'] : null,
				'ends_on'             => ActivityKind::COURSE === $d['kind'] ? $d['ends_on'] : null,
				'fund_mode'            => $d['fund_mode'],
				'fund_value'           => $d['fund_value'],
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
		if ( ! isset( $in['lesson_slots'] ) && ( isset( $in['lesson_weekday'] ) || isset( $in['lesson_start'] ) || isset( $in['lesson_end'] ) ) ) {
			unset( $current['lesson_slots'] ); // modifica con i campi singoli: ripartono da quelli
		}
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
				'vat_rate'             => $d['vat_rate'],
				'cancellable'          => ActivityKind::uses_sessions( $current['kind'] ) ? $d['cancellable'] : 0,
				'cancel_policy'        => ActivityKind::uses_sessions( $current['kind'] ) ? $d['cancel_policy'] : null,
				'booking_qr'           => ActivityKind::uses_sessions( $current['kind'] ) ? $d['booking_qr'] : 0,
				'lesson_weekday'       => ActivityKind::COURSE === $d['kind'] ? $d['lesson_weekday'] : 0,
				'billing'              => ActivityKind::COURSE === $d['kind'] ? $d['billing'] : 'monthly',
				'lesson_start'        => ActivityKind::COURSE === $d['kind'] ? $d['lesson_start'] : null,
				'lesson_slots'        => ActivityKind::COURSE === $d['kind'] ? $d['lesson_slots'] : null,
				'lesson_end'          => ActivityKind::COURSE === $d['kind'] ? $d['lesson_end'] : null,
				'location'            => ActivityKind::COURSE === $d['kind'] ? $d['location'] : null,
				'starts_on'           => ActivityKind::COURSE === $d['kind'] ? $d['starts_on'] : null,
				'ends_on'             => ActivityKind::COURSE === $d['kind'] ? $d['ends_on'] : null,
				'fund_mode'            => $d['fund_mode'],
				'fund_value'           => $d['fund_value'],
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

	private static function clean_time( $v ): ?string {
		$v = trim( (string) $v );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v ) ? $v : null;
	}

	private static function clean_date( $v ): ?string {
		$v  = trim( (string) $v );
		$dt = \DateTime::createFromFormat( 'Y-m-d', $v );
		return $dt && $dt->format( 'Y-m-d' ) === $v ? $v : null;
	}

	/** Tutte le lezioni del corso: settimanali (type weekly: day 1..7, start, end, from, until) e date uniche (type single: date, start, end). */
	public static function lessons( array $a ): array {
		$raw = ! empty( $a['lesson_slots'] ) ? json_decode( (string) $a['lesson_slots'], true ) : null;
		if ( is_array( $raw ) ) {
			return self::clean_slots( array( 'lesson_slots' => $raw ) );
		}
		return self::clean_slots( $a );
	}

	/** Lezioni settimanali: [ ['day' => 1..7 (lunedì = 1), 'start' => 'HH:MM'|null, 'end' => 'HH:MM'|null, 'from' => data|null, 'until' => data|null], ... ] */
	public static function slots( array $a ): array {
		return array_values( array_filter( self::lessons( $a ), function ( $s ) {
			return 'weekly' === $s['type'];
		} ) );
	}

	/** Date uniche di un corso (non ricorrenti). */
	public static function lesson_dates( array $a ): array {
		return array_values( array_filter( self::lessons( $a ), function ( $s ) {
			return 'single' === $s['type'];
		} ) );
	}

	/** Giorni della settimana (1..7) in cui si tiene il corso. @return int[] */
	public static function slot_days( array $a ): array {
		return array_values( array_unique( array_map( function ( $s ) {
			return (int) $s['day'];
		}, self::slots( $a ) ) ) );
	}

	/** Le lezioni come righe del modulo: data, orario, ricorrente (con "ogni giorno della settimana") e data di fine. @return array[] */
	public static function schedule_rows( array $a, string $default_from ): array {
		$rows = array();
		foreach ( self::lessons( $a ) as $s ) {
			if ( 'single' === $s['type'] ) {
				$rows[] = array( 'date' => $s['date'], 'from' => (string) $s['start'], 'to' => (string) $s['end'], 'recurring' => false, 'end' => '', 'repeat' => 'weekly' );
				continue;
			}
			$date   = Schedule::weekly_dates( $s['from'] ?: ( $a['starts_on'] ?: $default_from ), '9999-12-31', (int) $s['day'] );
			$rows[] = array( 'date' => $date ? $date[0] : $default_from, 'from' => (string) $s['start'], 'to' => (string) $s['end'], 'recurring' => true, 'end' => (string) ( $s['until'] ?: '' ), 'repeat' => 'weekly' );
		}
		return $rows;
	}

	private static function clean_slots( array $in ): array {
		$raw = array();
		if ( isset( $in['lesson_slots'] ) ) {
			$src = is_string( $in['lesson_slots'] ) ? json_decode( $in['lesson_slots'], true ) : $in['lesson_slots'];
			$raw = is_array( $src ) ? $src : array();
		} elseif ( (int) ( $in['lesson_weekday'] ?? 0 ) >= 1 ) {
			$raw = array( array( 'day' => $in['lesson_weekday'], 'start' => $in['lesson_start'] ?? '', 'end' => $in['lesson_end'] ?? '' ) );
		}
		$out = array();
		foreach ( $raw as $s ) {
			$start = self::clean_time( $s['start'] ?? '' );
			$end   = self::clean_time( $s['end'] ?? '' );
			if ( 'single' === ( $s['type'] ?? '' ) ) {
				$date = self::clean_date( $s['date'] ?? '' );
				if ( $date ) {
					$out[ 's|' . $date . '|' . (string) $start ] = array( 'type' => 'single', 'date' => $date, 'start' => $start, 'end' => $end );
				}
				continue;
			}
			$day = (int) ( $s['day'] ?? 0 );
			if ( $day < 1 || $day > 7 ) {
				continue;
			}
			$from  = self::clean_date( $s['from'] ?? '' );
			$until = self::clean_date( $s['until'] ?? '' );
			$out[ 'w|' . $day . '|' . (string) $start . '|' . (string) $from ] = array( 'type' => 'weekly', 'day' => $day, 'start' => $start, 'end' => $end, 'from' => $from, 'until' => $until );
		}
		$out = array_values( $out );
		usort( $out, function ( $x, $y ) {
			$kx = 'weekly' === $x['type'] ? '0' . $x['day'] . (string) $x['start'] : '1' . $x['date'] . (string) $x['start'];
			$ky = 'weekly' === $y['type'] ? '0' . $y['day'] . (string) $y['start'] : '1' . $y['date'] . (string) $y['start'];
			return strcmp( $kx, $ky );
		} );
		return array_slice( $out, 0, 60 );
	}

	private function normalize( array $in ): array {
		$slots  = self::clean_slots( $in );
		$weekly = array_values( array_filter( $slots, function ( $s ) {
			return 'weekly' === $s['type'];
		} ) );
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
			'vat_rate'             => Fiscal::clean_rate( $in['vat_rate'] ?? null ),
			'cancellable'          => ! empty( $in['cancellable'] ) ? 1 : 0,
			'cancel_policy'        => isset( $in['cancel_policy'] ) && CancelPolicy::is_valid( (string) $in['cancel_policy'] ) ? (string) $in['cancel_policy'] : null,
			'booking_qr'           => ! empty( $in['booking_qr'] ) ? 1 : 0,
			'lesson_weekday'       => $weekly ? $weekly[0]['day'] : 0,
			'slots'                => $slots,
			'lesson_slots'         => $slots ? wp_json_encode( $slots ) : null,
			'billing'              => isset( $in['billing'] ) && 'once' === $in['billing'] ? 'once' : 'monthly',
			'lesson_start'        => $weekly ? $weekly[0]['start'] : null,
			'lesson_end'          => $weekly ? $weekly[0]['end'] : null,
			'location'            => isset( $in['location'] ) && '' !== trim( (string) $in['location'] ) ? mb_substr( trim( (string) $in['location'] ), 0, 190 ) : null,
			'starts_on'           => self::clean_date( $in['starts_on'] ?? '' ),
			'ends_on'             => self::clean_date( $in['ends_on'] ?? '' ),
			'fund_mode'            => isset( $in['fund_mode'] ) && in_array( (string) $in['fund_mode'], array( FundShare::FIXED, FundShare::PERCENT ), true ) ? (string) $in['fund_mode'] : FundShare::NONE,
			'fund_value'           => max( 0, (int) ( $in['fund_value'] ?? 0 ) ),
			'notes'                => isset( $in['notes'] ) && '' !== trim( (string) $in['notes'] ) ? trim( (string) $in['notes'] ) : null,
		);
	}

	private function validate( array $d ): void {
		$instructor = $d['instructor_person_id'] ? Plugin::people()->get( $d['instructor_person_id'] ) : null;
		$errors     = Rules::validate_activity( $d, $instructor );
		$errors      = array_merge( $errors, FundShare::validate( $d['fund_mode'], $d['fund_value'], null !== $instructor ) );
		foreach ( $d['slots'] as $slot ) {
			if ( $slot['start'] && $slot['end'] && $slot['end'] <= $slot['start'] ) {
				$errors[] = 'L\'orario di fine deve essere dopo quello di inizio.';
				break;
			}
		}
		if ( $d['starts_on'] && $d['ends_on'] && $d['ends_on'] < $d['starts_on'] ) {
			$errors[] = 'La data di fine del corso è prima di quella di inizio.';
		}
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
			'end_time'     => $t( 'end_time' ),
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
		Waitlist::promote( $session_id ); // più posti: entrano quelli in lista d'attesa
	}

	/**
	 * Aggiunge le date descritte da regole (date uniche, giorni ricorrenti con data di fine): vedi {@see Schedule::expand()}.
	 * Le date già presenti (stesso giorno e orario) non si duplicano. Un evento una tantum ha una sola data.
	 *
	 * @return int date create
	 */
	public function add_dates( int $activity_id, array $rows, ?string $location = null, ?int $capacity = null ): int {
		$a     = $this->assert_uses_sessions( $this->get( $activity_id ) );
		$dates = Schedule::expand( $rows );
		if ( ! $dates ) {
			throw new \InvalidArgumentException( 'Aggiungi almeno una data.' );
		}
		if ( ActivityKind::EVENT === $a['kind'] && ( count( $dates ) > 1 || $this->sessions( $activity_id ) ) ) {
			throw new \InvalidArgumentException( 'Un evento una tantum ha una sola data: per più date scegli "Evento ricorrente".' );
		}
		$have = array();
		foreach ( $this->sessions( $activity_id ) as $s ) {
			$have[ $s['session_date'] . '|' . (string) $s['start_time'] ] = true;
		}
		$made = 0;
		foreach ( $dates as $d ) {
			if ( isset( $have[ $d['date'] . '|' . (string) $d['start'] ] ) ) {
				continue;
			}
			$n = $this->normalize_session( array( 'session_date' => $d['date'], 'start_time' => $d['start'], 'end_time' => $d['end'], 'location' => $location, 'capacity' => $capacity ) );
			$this->validate_session( $n );
			$this->insert_session( $activity_id, $n );
			$made++;
		}
		return $made;
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
		$tbl = Db::t( 'bookings' );
		// Posti e doppie prenotazioni: controllo e scrittura sotto blocco, così due richieste insieme non superano la capienza.
		list( $id, $fee ) = Db::with_lock(
			'apse_book_' . $session_id,
			function () use ( $tbl, $s, $a, $person, $session_id, $person_id ) {
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
					return array( (int) $existing['id'], $fee );
				}
				if ( ! $this->db()->insert( $tbl, array( 'session_id' => $session_id, 'person_id' => $person_id, 'status' => 'booked', 'fee_due_cents' => $fee, 'created_at' => Db::now() ) ) ) {
					throw new \InvalidArgumentException( 'Prenotazione non riuscita: riprova.' );
				}
				return array( (int) $this->db()->insert_id, $fee );
			}
		);
		Audit::log( 'booking.created', 'activity', (int) $a['id'], array( 'session' => $session_id, 'person_id' => $person_id, 'fee' => $fee ) );
		return $id;
	}

	/** Annulla la prenotazione. Gli eventuali pagamenti restano registrati (rimborso a mano). */
	public function cancel_booking( int $session_id, int $person_id ): void {
		$s = $this->session( $session_id );
		$this->db()->update( Db::t( 'bookings' ), array( 'status' => 'cancelled', 'cancelled_at' => Db::now() ), array( 'session_id' => $session_id, 'person_id' => $person_id ) );
		Audit::log( 'booking.cancelled', 'activity', $s ? (int) $s['activity_id'] : null, array( 'session' => $session_id, 'person_id' => $person_id ) );
		Waitlist::promote( $session_id ); // si è liberato un posto: entra il primo della lista d'attesa
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
				'SELECT b.*, p.first_name, p.last_name, p.type, p.card_number, p.email, p.host_person_id FROM ' . Db::t( 'bookings' ) . ' b '
				. 'JOIN ' . Db::t( 'people' ) . ' p ON p.id = b.person_id AND p.deleted_at IS NULL WHERE b.session_id = %d '
				. "ORDER BY (b.status = 'booked') DESC, p.last_name, p.first_name",
				$session_id
			),
			ARRAY_A
		) ?: array();
		$paid = array();
		foreach ( $this->db()->get_results(
			$this->db()->prepare( 'SELECT person_id, SUM(amount_cents + discount_cents) AS s FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND session_id = %d GROUP BY person_id", $session_id ),
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
			$this->db()->prepare( 'SELECT session_id, SUM(amount_cents + discount_cents) AS s FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND person_id = %d AND session_id IS NOT NULL GROUP BY session_id", $person_id ),
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

	// ---------- Partecipazioni degli ospiti (limite prima di doversi iscrivere) ----------

	/** Soglia di segnalazione: dopo quante partecipazioni un ospite viene evidenziato come da invitare a iscriversi (0 = nessuna segnalazione). Non blocca nulla. */
	public function guest_limit(): int {
		return max( 0, (int) Settings::get( 'guest_max_events' ) );
	}

	/**
	 * Tutto ciò a cui una persona partecipa: eventi prenotati (date non annullate) e corsi a cui è iscritta. Le più recenti per prime.
	 *
	 * @return array[] type (event|course), activity_id, activity_name, when (data o mese), session_id, checked_in (bool), ended (bool)
	 */
	public function participations( int $person_id ): array {
		$db  = $this->db();
		$out = array();
		foreach ( $db->get_results(
			$db->prepare(
				'SELECT b.session_id, b.checked_in_at, s.session_date, s.start_time, a.id AS activity_id, a.name FROM ' . Db::t( 'bookings' ) . ' b '
				. 'JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id AND s.cancelled_at IS NULL '
				. 'JOIN ' . Db::t( 'activities' ) . " a ON a.id = s.activity_id AND a.deleted_at IS NULL WHERE b.person_id = %d AND b.status = 'booked'",
				$person_id
			),
			ARRAY_A
		) ?: array() as $r ) {
			$out[] = array(
				'type' => 'event', 'activity_id' => (int) $r['activity_id'], 'activity_name' => $r['name'], 'when' => $r['session_date'], 'time' => $r['start_time'],
				'session_id' => (int) $r['session_id'], 'checked_in' => ! empty( $r['checked_in_at'] ), 'ended' => $r['session_date'] < current_time( 'Y-m-d' ),
			);
		}
		foreach ( $db->get_results(
			$db->prepare(
				'SELECT e.activity_id, e.start_month, e.end_month, a.name FROM ' . Db::t( 'enrollments' ) . ' e JOIN ' . Db::t( 'activities' ) . ' a ON a.id = e.activity_id AND a.deleted_at IS NULL WHERE e.person_id = %d',
				$person_id
			),
			ARRAY_A
		) ?: array() as $r ) {
			$out[] = array(
				'type' => 'course', 'activity_id' => (int) $r['activity_id'], 'activity_name' => $r['name'], 'when' => $r['start_month'], 'time' => null,
				'session_id' => 0, 'checked_in' => false, 'ended' => null !== $r['end_month'],
			);
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( (string) $b['when'], (string) $a['when'] );
		} );
		return $out;
	}

	/** @param int[] $person_ids @return array<int,int> persona => numero di partecipazioni (una sola interrogazione per più persone) */
	public function participation_counts( array $person_ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $person_ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in  = implode( ',', $ids );
		$db  = $this->db();
		$out = array_fill_keys( $ids, 0 );
		foreach ( $db->get_results(
			'SELECT b.person_id, COUNT(*) AS n FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id AND s.cancelled_at IS NULL '
			. 'JOIN ' . Db::t( 'activities' ) . " a ON a.id = s.activity_id AND a.deleted_at IS NULL WHERE b.status = 'booked' AND b.person_id IN ($in) GROUP BY b.person_id",
			ARRAY_A
		) ?: array() as $r ) {
			$out[ (int) $r['person_id'] ] += (int) $r['n'];
		}
		foreach ( $db->get_results(
			'SELECT e.person_id, COUNT(*) AS n FROM ' . Db::t( 'enrollments' ) . ' e JOIN ' . Db::t( 'activities' ) . " a ON a.id = e.activity_id AND a.deleted_at IS NULL WHERE e.person_id IN ($in) GROUP BY e.person_id",
			ARRAY_A
		) ?: array() as $r ) {
			$out[ (int) $r['person_id'] ] += (int) $r['n'];
		}
		return $out;
	}

	/** Partecipazioni di una persona e soglia di segnalazione (nessun blocco). @return array count, max (soglia, 0 = nessuna segnalazione), flagged (ha raggiunto la soglia), items */
	public function guest_status( int $person_id ): array {
		$items = $this->participations( $person_id );
		$max   = $this->guest_limit();
		$n     = count( $items );
		return array( 'count' => $n, 'max' => $max, 'flagged' => $max > 0 && $n >= $max, 'items' => $items );
	}


	// ---------- Gestori dell'evento e registrazione degli ingressi ----------

	/** Soci abilitati alla gestione di un evento (oltre al referente e agli amministratori). */
	public function staff( int $activity_id ): array {
		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT s.id AS staff_id, p.id AS person_id, p.first_name, p.last_name, p.type, p.card_number, s.can_cash FROM ' . Db::t( 'activity_staff' ) . ' s '
				. 'JOIN ' . Db::t( 'people' ) . ' p ON p.id = s.person_id AND p.deleted_at IS NULL WHERE s.activity_id = %d ORDER BY p.last_name, p.first_name',
				$activity_id
			),
			ARRAY_A
		) ?: array();
	}

	public function is_staff( int $activity_id, int $person_id ): bool {
		return (bool) $this->db()->get_var(
			$this->db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'activity_staff' ) . ' WHERE activity_id = %d AND person_id = %d', $activity_id, $person_id )
		);
	}

	/** @throws \InvalidArgumentException */
	public function add_staff( int $activity_id, int $person_id, bool $can_cash = false ): void {
		$a = $this->get( $activity_id );
		$p = Plugin::people()->get( $person_id );
		if ( ! $a || ! ActivityKind::uses_sessions( $a['kind'] ) ) {
			throw new \InvalidArgumentException( 'I gestori si indicano per gli eventi e gli eventi ricorrenti.' );
		}
		if ( ! $p || ! MemberType::is_member( $p['type'] ) ) {
			throw new \InvalidArgumentException( 'Può gestire un evento solo un socio o volontario (non un ospite).' );
		}
		if ( $a['instructor_person_id'] && (int) $a['instructor_person_id'] === $person_id ) {
			throw new \InvalidArgumentException( 'È già il referente dell\'evento e dispone di tutti i permessi di gestione.' );
		}
		if ( $this->is_staff( $activity_id, $person_id ) ) {
			throw new \InvalidArgumentException( 'È già tra i gestori dell\'evento.' );
		}
		$this->db()->insert( Db::t( 'activity_staff' ), array( 'activity_id' => $activity_id, 'person_id' => $person_id, 'can_cash' => $can_cash ? 1 : 0, 'created_at' => Db::now() ) );
		Audit::log( 'event_staff.added', 'activity', $activity_id, array( 'person' => $person_id, 'can_cash' => $can_cash ) );
	}

	/** Il gestore può incassare il biglietto sul posto? (il referente e gli amministratori lo possono sempre: qui si valuta solo lo staff indicato). */
	public function staff_can_cash( int $activity_id, int $person_id ): bool {
		return (bool) $this->db()->get_var(
			$this->db()->prepare( 'SELECT can_cash FROM ' . Db::t( 'activity_staff' ) . ' WHERE activity_id = %d AND person_id = %d', $activity_id, $person_id )
		);
	}

	public function set_staff_cash( int $activity_id, int $person_id, bool $can_cash ): void {
		$this->db()->update( Db::t( 'activity_staff' ), array( 'can_cash' => $can_cash ? 1 : 0 ), array( 'activity_id' => $activity_id, 'person_id' => $person_id ) );
		Audit::log( 'event_staff.cash', 'activity', $activity_id, array( 'person' => $person_id, 'can_cash' => $can_cash ) );
	}

	/** Posti di una data: capienza (null = illimitati), prenotati, liberi (null = illimitati). */
	public function seats( int $session_id ): array {
		$s = $this->session( $session_id );
		if ( ! $s ) {
			return array( 'capacity' => null, 'taken' => 0, 'free' => null );
		}
		$taken = (int) $this->db()->get_var( $this->db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " WHERE session_id = %d AND status = 'booked'", $session_id ) );
		$cap   = null === $s['capacity'] ? null : (int) $s['capacity'];
		return array( 'capacity' => $cap, 'taken' => $taken, 'free' => null === $cap ? null : max( 0, $cap - $taken ) );
	}

	public function remove_staff( int $activity_id, int $person_id ): void {
		$this->db()->delete( Db::t( 'activity_staff' ), array( 'activity_id' => $activity_id, 'person_id' => $person_id ) );
		Audit::log( 'event_staff.removed', 'activity', $activity_id, array( 'person' => $person_id ) );
	}

	/** Eventi (con date) che una persona gestisce: quelli che tiene come referente e quelli in cui è tra i gestori. @return int[] */
	public function managed_activity_ids( int $person_id ): array {
		$ids = $this->db()->get_col(
			$this->db()->prepare(
				'SELECT a.id FROM ' . Db::t( 'activities' ) . " a WHERE a.deleted_at IS NULL AND a.kind IN ('event','recurring') AND (a.instructor_person_id = %d OR a.id IN (SELECT activity_id FROM " . Db::t( 'activity_staff' ) . ' WHERE person_id = %d)) ORDER BY a.social_year DESC, a.name',
				$person_id,
				$person_id
			)
		) ?: array();
		return array_map( 'intval', $ids );
	}

	/**
	 * Registra (o annulla) l'ingresso di una persona prenotata a una data.
	 *
	 * @param bool $force consente di registrare anche in un giorno diverso da quello dell'evento (solo amministratori)
	 * @return array status (recorded|already|undone|none), at (ora, o null)
	 * @throws \InvalidArgumentException
	 */
	public function check_in( int $session_id, int $person_id, bool $undo = false, bool $force = false ): array {
		$s = $this->session( $session_id );
		$b = $this->booking( $session_id, $person_id );
		if ( ! $s || ! $b || 'booked' !== $b['status'] ) {
			throw new \InvalidArgumentException( 'Nessuna prenotazione attiva per questa persona.' );
		}
		if ( ! empty( $s['cancelled_at'] ) ) {
			throw new \InvalidArgumentException( 'Questa data dell\'evento è stata annullata.' );
		}
		$tbl = Db::t( 'bookings' );
		if ( $undo ) {
			if ( empty( $b['checked_in_at'] ) ) {
				return array( 'status' => 'none', 'at' => null );
			}
			$this->db()->update( $tbl, array( 'checked_in_at' => null, 'checked_in_by' => null ), array( 'id' => (int) $b['id'] ) );
			Audit::log( 'checkin.undone', 'activity', (int) $s['activity_id'], array( 'session' => $session_id, 'person' => $person_id ) );
			return array( 'status' => 'undone', 'at' => null );
		}
		if ( ! empty( $b['checked_in_at'] ) ) {
			return array( 'status' => 'already', 'at' => $b['checked_in_at'] );
		}
		if ( ! $force && $s['session_date'] !== current_time( 'Y-m-d' ) ) {
			throw new \InvalidArgumentException( 'Gli ingressi si registrano nel giorno dell\'evento (' . ( new \DateTimeImmutable( $s['session_date'] ) )->format( 'd/m/Y' ) . ').' );
		}
		$now = Db::now();
		$this->db()->update( $tbl, array( 'checked_in_at' => $now, 'checked_in_by' => get_current_user_id() ?: null ), array( 'id' => (int) $b['id'] ) );
		Audit::log( 'checkin.recorded', 'activity', (int) $s['activity_id'], array( 'session' => $session_id, 'person' => $person_id ) );
		return array( 'status' => 'recorded', 'at' => $now );
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

	/** Una sola transazione per tutto il plugin (quella della prima nota): dentro un'operazione unica ci si unisce, invece di confermarla a metà. */
	private function in_transaction( callable $fn ) {
		return Plugin::ledger()->in_batch( $fn );
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
		$enrollee = Plugin::people()->get( $person_id );
		if ( ! $enrollee ) {
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
			"SELECT activity_id, person_id, COALESCE(competence_month, DATE_FORMAT(tx_date, '%Y-%m')) AS ym, SUM(amount_cents + discount_cents) AS s "
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
		$fee = $this->fee_for( $activity, $person_type );
		if ( 'once' === ( $activity['billing'] ?? 'monthly' ) ) {
			return PaymentCalc::compute_once( $fee, $enrollment['start_month'], $enrollment['end_month'], $paid_by_month );
		}
		$end = $enrollment['end_month'];
		if ( ! empty( $activity['ends_on'] ) ) { // il corso ha una data di fine: dopo quel mese non si rinnova più
			$last = substr( (string) $activity['ends_on'], 0, 7 );
			$end  = null === $end ? $last : min( $end, $last );
		}
		return PaymentCalc::compute(
			$fee,
			$enrollment['start_month'],
			$end,
			substr( Db::today(), 0, 7 ),
			SocialYear::from_label( $activity['social_year'], Settings::start_month() ),
			$paid_by_month,
			self::slot_days( $activity ),
			Db::today()
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
				'SELECT e.*, p.first_name, p.last_name, p.type, p.card_number, p.email, p.phone, p.host_person_id FROM ' . Db::t( 'enrollments' ) . ' e '
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
				'SELECT e.*, a.name AS activity_name, a.social_year, a.kind, a.fee_cents, a.guest_fee_cents, a.lesson_weekday, a.lesson_slots, a.billing, a.ends_on FROM ' . Db::t( 'enrollments' ) . ' e '
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
				'fee_cents' => $e['fee_cents'], 'guest_fee_cents' => $e['guest_fee_cents'], 'lesson_weekday' => $e['lesson_weekday'], 'lesson_slots' => $e['lesson_slots'], 'billing' => $e['billing'], 'ends_on' => $e['ends_on'],
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

	/** Attività tenute da una persona (referente). */
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
