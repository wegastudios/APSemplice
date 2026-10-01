<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Soci, ospiti e tessere. Ogni socio (fondatore, ordinario, volontario) corrisponde a un utente
 * WordPress (email obbligatoria); l'ospite è una persona collegata al socio che lo ospita.
 * Gli errori di regola sono \InvalidArgumentException con un messaggio mostrabile all'utente.
 */
class PeopleService {

	private function db(): \wpdb {
		return Db::db();
	}

	// ---------- Lettura ----------

	public function get( int $id ): ?array {
		$row = $this->db()->get_row( $this->db()->prepare( 'SELECT * FROM ' . Db::t( 'people' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $row ?: null;
	}

	public function full_name( array $p ): string {
		return trim( $p['first_name'] . ' ' . $p['last_name'] );
	}

	public function find_by_card( string $card, ?int $except_id = null ): ?array {
		$row = $this->db()->get_row(
			$this->db()->prepare( 'SELECT * FROM ' . Db::t( 'people' ) . ' WHERE deleted_at IS NULL AND UPPER(card_number) = UPPER(%s) AND id <> %d LIMIT 1', $card, (int) $except_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function find_by_email( string $email, ?int $except_id = null ): ?array {
		$row = $this->db()->get_row(
			$this->db()->prepare( 'SELECT * FROM ' . Db::t( 'people' ) . ' WHERE deleted_at IS NULL AND LOWER(email) = LOWER(%s) AND id <> %d LIMIT 1', $email, (int) $except_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/** Prossimo numero libero: massimo tra le tessere puramente numeriche + 1. */
	public function next_free_card(): string {
		$cards = $this->db()->get_col( 'SELECT card_number FROM ' . Db::t( 'people' ) . ' WHERE deleted_at IS NULL AND card_number IS NOT NULL' );
		$max   = 0;
		foreach ( $cards as $c ) {
			if ( ctype_digit( trim( $c ) ) ) {
				$max = max( $max, (int) $c );
			}
		}
		return (string) ( $max + 1 );
	}

	/**
	 * @param array $f type, q (testo libero), status (active|expired), host_id
	 * @return array[] righe con 'active_until' (ultimo giorno di validità della tessera) e 'host_name'
	 */
	public function search( array $f = array() ): array {
		$db    = $this->db();
		$today = Db::today();
		$where = array( 'p.deleted_at IS NULL' );
		$args  = array( $today );
		if ( ! empty( $f['type'] ) ) {
			$where[] = 'p.type = %s';
			$args[]  = $f['type'];
		}
		if ( ! empty( $f['host_id'] ) ) {
			$where[] = 'p.host_person_id = %d';
			$args[]  = (int) $f['host_id'];
		}
		if ( isset( $f['q'] ) && '' !== trim( $f['q'] ) ) {
			$like    = '%' . $db->esc_like( trim( $f['q'] ) ) . '%';
			$where[] = '(p.first_name LIKE %s OR p.last_name LIKE %s OR CONCAT(p.first_name, " ", p.last_name) LIKE %s OR CONCAT(p.last_name, " ", p.first_name) LIKE %s OR p.card_number LIKE %s OR p.email LIKE %s OR p.tax_code LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like, $like, $like );
		}
		$sql = 'SELECT p.*, '
			. '(SELECT MAX(m.valid_to) FROM ' . Db::t( 'memberships' ) . ' m WHERE m.person_id = p.id AND m.deleted_at IS NULL AND m.valid_from <= %s) AS active_until, '
			. 'CONCAT(h.first_name, " ", h.last_name) AS host_name '
			. 'FROM ' . Db::t( 'people' ) . ' p LEFT JOIN ' . Db::t( 'people' ) . ' h ON h.id = p.host_person_id '
			. 'WHERE ' . implode( ' AND ', $where ) . ' ORDER BY p.last_name, p.first_name';
		$rows = $db->get_results( $db->prepare( $sql, $args ), ARRAY_A ) ?: array();

		if ( ! empty( $f['status'] ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					function ( $r ) use ( $f, $today ) {
						if ( MemberType::GUEST === $r['type'] ) {
							return false;
						}
						$active = ! empty( $r['active_until'] ) && $r['active_until'] >= $today;
						return 'active' === $f['status'] ? $active : ! $active;
					}
				)
			);
		}
		return $rows;
	}

	public function guests_of( int $member_id ): array {
		return $this->search( array( 'host_id' => $member_id ) );
	}

	public function active_until( int $person_id, ?string $date = null ): ?string {
		$date = $date ?? Db::today();
		$v    = $this->db()->get_var(
			$this->db()->prepare( 'SELECT MAX(valid_to) FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = %d AND deleted_at IS NULL AND valid_from <= %s', $person_id, $date )
		);
		return $v ?: null;
	}

	public function is_active_member( int $person_id, ?string $date = null ): bool {
		$date  = $date ?? Db::today();
		$until = $this->active_until( $person_id, $date );
		return null !== $until && $until >= $date;
	}

	/** Iscrizioni (tessere) di una persona, dalla più recente. */
	public function memberships( int $person_id ): array {
		return $this->db()->get_results(
			$this->db()->prepare( 'SELECT * FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = %d AND deleted_at IS NULL ORDER BY valid_from DESC', $person_id ),
			ARRAY_A
		) ?: array();
	}

	/** Numero di soci iscritti nell'anno sociale, per tipo. @return array tipo => numero */
	public function count_by_type_for_year( SocialYear $year ): array {
		$db   = $this->db();
		$rows = $db->get_results(
			$db->prepare(
				'SELECT p.type, COUNT(DISTINCT p.id) AS n FROM ' . Db::t( 'people' ) . ' p JOIN ' . Db::t( 'memberships' ) . ' m ON m.person_id = p.id '
				. 'AND m.deleted_at IS NULL AND m.valid_from <= %s AND m.valid_to >= %s WHERE p.deleted_at IS NULL GROUP BY p.type',
				$year->end()->format( 'Y-m-d' ),
				$year->start()->format( 'Y-m-d' )
			),
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $r ) {
			$out[ $r['type'] ] = (int) $r['n'];
		}
		return $out;
	}

	// ---------- Scrittura ----------

	private function normalize( array $d ): array {
		$t = function ( $k ) use ( $d ) {
			$v = isset( $d[ $k ] ) ? trim( (string) $d[ $k ] ) : '';
			return '' === $v ? null : $v;
		};
		return array(
			'type'           => (string) ( $d['type'] ?? '' ),
			'card_number'    => $t( 'card_number' ),
			'first_name'     => (string) ( $t( 'first_name' ) ?? '' ),
			'last_name'      => (string) ( $t( 'last_name' ) ?? '' ),
			'email'          => null === $t( 'email' ) ? null : strtolower( $t( 'email' ) ),
			'phone'          => $t( 'phone' ),
			'tax_code'       => null === $t( 'tax_code' ) ? null : strtoupper( str_replace( ' ', '', $t( 'tax_code' ) ) ),
			'host_person_id' => ! empty( $d['host_person_id'] ) ? (int) $d['host_person_id'] : null,
			'joined_on'      => $t( 'joined_on' ),
			'notes'          => $t( 'notes' ),
		);
	}

	private function assert_valid( array $d, ?int $except_id ): void {
		$host   = $d['host_person_id'] ? $this->get( (int) $d['host_person_id'] ) : null;
		$errors = Rules::validate_person( $d, $host );
		if ( $errors ) {
			throw new \InvalidArgumentException( implode( ' ', $errors ) );
		}
		if ( null !== $d['card_number'] ) {
			$holder = $this->find_by_card( $d['card_number'], $except_id );
			if ( $holder ) {
				throw new \InvalidArgumentException( 'La tessera ' . $d['card_number'] . ' è già assegnata a ' . $this->full_name( $holder ) . '.' );
			}
		}
		if ( null !== $d['email'] ) {
			$holder = $this->find_by_email( $d['email'], $except_id );
			if ( $holder ) {
				throw new \InvalidArgumentException( 'L\'email ' . $d['email'] . ' è già usata da ' . $this->full_name( $holder ) . '.' );
			}
		}
	}

	/** Crea una persona. Per i soci crea (o collega) l'utente WordPress con la stessa email. */
	public function create( array $input ): int {
		$d = $this->normalize( $input );
		$this->assert_valid( $d, null );

		$user_id = null;
		if ( MemberType::is_member( $d['type'] ) ) {
			$user_id = $this->ensure_wp_user( $d, null );
		}
		$now = Db::now();
		$ok  = $this->db()->insert(
			Db::t( 'people' ),
			array(
				'wp_user_id'     => $user_id,
				'type'           => $d['type'],
				'card_number'    => $d['card_number'],
				'first_name'     => $d['first_name'],
				'last_name'      => $d['last_name'],
				'email'          => $d['email'],
				'phone'          => $d['phone'],
				'tax_code'       => $d['tax_code'],
				'host_person_id' => $d['host_person_id'],
				'joined_on'      => $d['joined_on'] ?: Db::today(),
				'notes'          => $d['notes'],
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'Impossibile salvare la persona (errore del database).' );
		}
		$id = (int) $this->db()->insert_id;
		if ( MemberType::is_auto_renewed( $d['type'] ) ) {
			$this->set_founder_membership( $id, $d['joined_on'] ?: Db::today() );
		}
		return $id;
	}

	public function update( int $id, array $input ): void {
		$current = $this->get( $id );
		if ( ! $current ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		$d = $this->normalize( array_merge( $current, $input ) );
		if ( MemberType::is_member( $current['type'] ) && MemberType::GUEST === $d['type'] ) {
			throw new \InvalidArgumentException( 'Un socio non può diventare ospite.' );
		}
		$this->assert_valid( $d, $id );

		$user_id = $current['wp_user_id'] ? (int) $current['wp_user_id'] : null;
		if ( MemberType::is_member( $d['type'] ) ) {
			if ( null === $user_id ) {
				$user_id = $this->ensure_wp_user( $d, $id ); // ad es. ospite diventato socio
			} else {
				$this->sync_wp_user( $user_id, $d );
			}
		}

		$this->db()->update(
			Db::t( 'people' ),
			array(
				'wp_user_id'     => $user_id,
				'type'           => $d['type'],
				'card_number'    => $d['card_number'],
				'first_name'     => $d['first_name'],
				'last_name'      => $d['last_name'],
				'email'          => $d['email'],
				'phone'          => $d['phone'],
				'tax_code'       => $d['tax_code'],
				'host_person_id' => $d['host_person_id'],
				'joined_on'      => $d['joined_on'] ?: $current['joined_on'],
				'notes'          => $d['notes'],
				'updated_at'     => Db::now(),
			),
			array( 'id' => $id )
		);

		$was_founder = MemberType::is_auto_renewed( $current['type'] );
		$is_founder  = MemberType::is_auto_renewed( $d['type'] );
		if ( $is_founder ) {
			$this->set_founder_membership( $id, $d['joined_on'] ?: (string) $current['joined_on'] );
		} elseif ( $was_founder ) {
			$this->db()->update( Db::t( 'memberships' ), array( 'deleted_at' => Db::now() ), array( 'person_id' => $id, 'social_year' => 'FOUNDER' ) );
		}
	}

	/** Eliminazione logica: libera la tessera e il collegamento all'utente WordPress (che NON viene cancellato). */
	public function delete( int $id ): void {
		$p = $this->get( $id );
		if ( ! $p ) {
			return;
		}
		if ( $this->guests_of( $id ) ) {
			throw new \InvalidArgumentException( 'Il socio ha degli ospiti collegati: eliminali o spostali prima.' );
		}
		$this->db()->update(
			Db::t( 'people' ),
			array( 'deleted_at' => Db::now(), 'card_number' => null, 'wp_user_id' => null ),
			array( 'id' => $id )
		);
	}

	// ---------- Utenti WordPress ----------

	private function ensure_wp_user( array $d, ?int $person_id ): int {
		$existing = get_user_by( 'email', $d['email'] );
		if ( $existing ) {
			$linked = $this->db()->get_var(
				$this->db()->prepare( 'SELECT id FROM ' . Db::t( 'people' ) . ' WHERE wp_user_id = %d AND deleted_at IS NULL AND id <> %d', $existing->ID, (int) $person_id )
			);
			if ( $linked ) {
				throw new \InvalidArgumentException( 'L\'utente WordPress con questa email è già collegato a un altro socio.' );
			}
			return (int) $existing->ID; // si collega l'utente esistente senza cambiargli il ruolo
		}
		$uid = wp_insert_user(
			array(
				'user_login'   => $this->unique_login( $d['email'] ),
				'user_email'   => $d['email'],
				'user_pass'    => wp_generate_password( 24 ),
				'first_name'   => $d['first_name'],
				'last_name'    => $d['last_name'],
				'display_name' => trim( $d['first_name'] . ' ' . $d['last_name'] ),
				'role'         => Plugin::ROLE_MEMBER,
			)
		);
		if ( is_wp_error( $uid ) ) {
			throw new \InvalidArgumentException( 'Impossibile creare l\'utente WordPress: ' . $uid->get_error_message() );
		}
		return (int) $uid;
	}

	private function unique_login( string $email ): string {
		$base  = sanitize_user( $email, true );
		$login = $base;
		$i     = 2;
		while ( username_exists( $login ) ) {
			$login = $base . $i++;
		}
		return $login;
	}

	/** Allinea email e nome dell'utente WordPress, ma solo se è un utente "solo socio" (mai admin o altri ruoli). */
	private function sync_wp_user( int $user_id, array $d ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || array( Plugin::ROLE_MEMBER ) !== array_values( $user->roles ) ) {
			return;
		}
		$args = array( 'ID' => $user_id, 'first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'display_name' => trim( $d['first_name'] . ' ' . $d['last_name'] ) );
		if ( null !== $d['email'] && strtolower( $user->user_email ) !== $d['email'] ) {
			$args['user_email'] = $d['email'];
		}
		$res = wp_update_user( $args );
		if ( is_wp_error( $res ) ) {
			throw new \InvalidArgumentException( 'Impossibile aggiornare l\'utente WordPress: ' . $res->get_error_message() );
		}
	}

	// ---------- Iscrizioni (tessere) ----------

	/** Il fondatore ha una sola iscrizione che dura N anni (default 99): sempre rinnovata. */
	private function set_founder_membership( int $person_id, string $joined_on ): void {
		$from = new \DateTimeImmutable( $joined_on ?: Db::today() );
		$to   = $from->modify( '+' . (int) Settings::get( 'founder_years' ) . ' years' );
		$this->upsert_membership( $person_id, 'FOUNDER', $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ), 'founder', null );
	}

	private function upsert_membership( int $person_id, string $label, string $from, string $to, string $source, ?int $tx_id ): void {
		$db  = $this->db();
		$tbl = Db::t( 'memberships' );
		$row = $db->get_row( $db->prepare( "SELECT id FROM $tbl WHERE person_id = %d AND social_year = %s", $person_id, $label ), ARRAY_A );
		$data = array( 'valid_from' => $from, 'valid_to' => $to, 'source' => $source, 'transaction_id' => $tx_id, 'deleted_at' => null );
		if ( $row ) {
			$db->update( $tbl, $data, array( 'id' => (int) $row['id'] ) );
		} else {
			$db->insert( $tbl, array_merge( $data, array( 'person_id' => $person_id, 'social_year' => $label, 'created_at' => Db::now() ) ) );
		}
	}

	/** Iscrive o toglie l'iscrizione per un anno sociale (es. "2025/2026"). Non vale per fondatori e ospiti. */
	public function set_membership( int $person_id, string $year_label, bool $enabled, string $source = 'manual', ?int $tx_id = null ): void {
		$p = $this->get( $person_id );
		if ( ! $p ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		if ( MemberType::GUEST === $p['type'] ) {
			throw new \InvalidArgumentException( 'Gli ospiti non sono soci: non hanno iscrizione.' );
		}
		if ( MemberType::is_auto_renewed( $p['type'] ) ) {
			throw new \InvalidArgumentException( 'Il socio fondatore ha la tessera sempre rinnovata.' );
		}
		$year = SocialYear::from_label( $year_label, Settings::start_month() );
		if ( $enabled ) {
			$this->upsert_membership( $person_id, $year->label(), $year->start()->format( 'Y-m-d' ), $year->end()->format( 'Y-m-d' ), $source, $tx_id );
		} else {
			$this->db()->update( Db::t( 'memberships' ), array( 'deleted_at' => Db::now() ), array( 'person_id' => $person_id, 'social_year' => $year->label() ) );
		}
	}

	/** Annulla l'iscrizione nata da un incasso (usato quando si annulla il movimento). */
	public function remove_membership_of_transaction( int $tx_id ): void {
		$this->db()->update( Db::t( 'memberships' ), array( 'deleted_at' => Db::now() ), array( 'transaction_id' => $tx_id ) );
	}
}
