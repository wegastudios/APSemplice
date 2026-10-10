<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Chi può fare cosa. I permessi NON derivano da ruoli WordPress ma dai dati:
 *
 *  - amministratore del sito (capability `asem_manage`): tutto;
 *  - "socio e volontario": solo sulle attività di cui è il referente;
 *  - socio: solo sui propri dati.
 *
 * La regola è in {@see Access::decide()} (pura, testata). Le "capability meta" sono registrate
 * in WordPress, quindi ovunque si può scrivere `current_user_can( 'asem_notify_activity', $activity_id )`.
 */
final class Access {

	/** Capability meta => ammesse da decide(). Il primo argomento di current_user_can è l'id dell'oggetto. */
	const ABILITIES = array(
		'asem_view_person',        // id persona: vedere la scheda
		'asem_edit_own_profile',   // id persona: modificare i propri dati
		'asem_view_payments',      // id persona: vedere i propri pagamenti
		'asem_add_guest',          // id persona (il socio ospitante): aggiungere un ospite
		'asem_book_for',           // id persona: prenotarla a un evento (sé stessi o un proprio ospite)
		'asem_view_activity',      // id attività: vedere i dati base
		'asem_view_participants',  // id attività: vedere chi è iscritto
		'asem_notify_activity',    // id attività: inviare un avviso ufficiale agli iscritti
		'asem_manage_event',       // id attività: gestire un evento (lista prenotati, registrazione ingressi): referente e gestori indicati
		'asem_door_cash',          // id attivita': incassare il biglietto sul posto a chi non ha prenotato (referente e gestori con l'incasso abilitato)
		'asem_add_expense',        // (nessun oggetto) tesoriere: registrare spese dall'area riservata
		'asem_collect',            // (nessun oggetto) tesoriere: incassare dall'area riservata
		'asem_register_member',    // (nessun oggetto) tesoriere: iscrivere un nuovo socio dall'area riservata
	);

	/**
	 * @param string     $ability  una delle ABILITIES
	 * @param bool       $is_admin l'utente ha la capability di amministrazione del plugin
	 * @param array|null $actor    persona collegata all'utente (con 'id' e 'type'), null se non è un socio
	 * @param array      $ctx      person_id | instructor_person_id, is_enrolled
	 */
	public static function decide( string $ability, bool $is_admin, ?array $actor, array $ctx ): bool {
		if ( $is_admin ) {
			return true;
		}
		if ( null === $actor || ! in_array( $ability, self::ABILITIES, true ) ) {
			return false;
		}
		$me = (int) $actor['id'];
		switch ( $ability ) {
			case 'asem_view_person':
			case 'asem_edit_own_profile':
			case 'asem_view_payments':
				return (int) ( $ctx['person_id'] ?? 0 ) === $me;
			case 'asem_add_guest':
				return (int) ( $ctx['person_id'] ?? 0 ) === $me && MemberType::is_member( (string) $actor['type'] );
			case 'asem_book_for':
				return MemberType::is_member( (string) $actor['type'] ) && ( (int) ( $ctx['person_id'] ?? 0 ) === $me || (int) ( $ctx['host_person_id'] ?? 0 ) === $me );
			case 'asem_manage_event':
				return MemberType::is_member( (string) $actor['type'] ) && ( self::is_instructor( $actor, $ctx ) || ! empty( $ctx['is_staff'] ) || ! empty( $ctx['is_entity_staff'] ) ); // referente, staff dell'evento o staff dell'ente (su tutti gli eventi)
			case 'asem_door_cash': // referente, staff dell'evento con l'incasso abilitato, tesoriere (vendita degli eventi)
				return MemberType::is_member( (string) $actor['type'] ) && ( self::is_instructor( $actor, $ctx ) || ! empty( $ctx['can_cash'] ) || ! empty( $ctx['is_treasurer'] ) );
			case 'asem_collect':
			case 'asem_register_member':
			case 'asem_add_expense':
				return ! empty( $ctx['is_treasurer'] ) && MemberType::is_member( (string) $actor['type'] );
			case 'asem_view_activity':
				return self::is_instructor( $actor, $ctx ) || ! empty( $ctx['is_enrolled'] );
			case 'asem_view_participants':
			case 'asem_notify_activity':
				return self::is_instructor( $actor, $ctx );
		}
		return false;
	}

	private static function is_instructor( array $actor, array $ctx ): bool {
		return MemberType::can_teach( (string) $actor['type'] ) && (int) ( $ctx['instructor_person_id'] ?? 0 ) === (int) $actor['id'];
	}

	// ---------- Collegamento con WordPress ----------

	public static function register(): void {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
		add_filter( 'user_has_cap', array( __CLASS__, 'board_as_secretary' ), 10, 4 );
	}

	/** Presidente e vicepresidente (in regola con la tessera) agiscono come la segreteria su tutto. */
	public static function board_as_secretary( $allcaps, $caps, $args, $user ) {
		if ( empty( $allcaps[ Plugin::CAP_OPS ] ) && in_array( Plugin::CAP_OPS, (array) $caps, true ) && $user instanceof \WP_User && self::is_board_operator( (int) $user->ID ) ) {
			$allcaps[ Plugin::CAP_OPS ] = true;
		}
		return $allcaps;
	}

	/** @return bool l'utente è collegato a un socio in regola con la carica di presidente o vicepresidente */
	public static function is_board_operator( int $user_id ): bool {
		if ( $user_id <= 0 || ! Edition::allows( 'member_area' ) ) {
			return false;
		}
		$p = self::person_for_user( $user_id );
		return $p && in_array( (string) $p['board_role'], array( BoardRole::PRESIDENT, BoardRole::VICE_PRESIDENT ), true ) && Plugin::people()->is_active_member( (int) $p['id'] );
	}

	const STAFF_META = 'asem_staff';

	/** Staff dell'ente: verifica gli accessi a tutti gli eventi (l'incasso resta una scelta per singolo evento). */
	public static function is_entity_staff( int $user_id ): bool {
		return $user_id > 0 && '1' === (string) get_user_meta( $user_id, self::STAFF_META, true );
	}

	public static function set_entity_staff( int $user_id, bool $on ): void {
		if ( $on ) {
			update_user_meta( $user_id, self::STAFF_META, '1' );
		} else {
			delete_user_meta( $user_id, self::STAFF_META );
		}
	}

	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, self::ABILITIES, true ) ) {
			return $caps;
		}
		return self::user_can( (int) $user_id, $cap, (int) ( $args[0] ?? 0 ) ) ? array( 'exist' ) : array( 'do_not_allow' );
	}

	/** La persona collegata a un utente WordPress (null se l'utente non è un socio). */
	public static function person_for_user( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}
		$row = Db::db()->get_row(
			Db::db()->prepare( 'SELECT * FROM ' . Db::t( 'people' ) . ' WHERE wp_user_id = %d AND deleted_at IS NULL', $user_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function current_person(): ?array {
		return self::person_for_user( get_current_user_id() );
	}

	const TREASURER_META = 'asem_treasurer';

	/** Tesoriere: socio o volontario a cui l'amministratore ha dato il permesso di registrare spese dall'area riservata. */
	public static function is_treasurer( int $user_id ): bool {
		return $user_id > 0 && '1' === (string) get_user_meta( $user_id, self::TREASURER_META, true );
	}

	public static function set_treasurer( int $user_id, bool $on ): void {
		if ( $on ) {
			update_user_meta( $user_id, self::TREASURER_META, '1' );
		} else {
			delete_user_meta( $user_id, self::TREASURER_META );
		}
	}

	public static function is_admin_user( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, Plugin::CAP_OPS );
	}

	public static function user_can( int $user_id, string $ability, int $object_id ): bool {
		$is_admin = self::is_admin_user( $user_id );
		if ( $is_admin ) {
			return true;
		}
		// Licenza non in regola: l'accesso di soci e volontari è sospeso (gli amministratori restano, coperti dal popup).
		if ( ! Edition::allows( 'member_area' ) ) {
			return false;
		}
		$actor = self::person_for_user( $user_id );
		if ( ! $actor ) {
			return false;
		}
		$ctx = array( 'person_id' => $object_id );
		if ( in_array( $ability, array( 'asem_add_expense', 'asem_collect', 'asem_register_member' ), true ) ) {
			$ctx['is_treasurer'] = self::is_treasurer( $user_id );
		}
		if ( 'asem_book_for' === $ability ) {
			$target = Plugin::people()->get( $object_id );
			if ( ! $target ) {
				return false;
			}
			$ctx['host_person_id'] = (int) $target['host_person_id'];
		}
		if ( in_array( $ability, array( 'asem_view_activity', 'asem_view_participants', 'asem_notify_activity', 'asem_manage_event', 'asem_door_cash' ), true ) ) {
			$activity = Plugin::activities()->get( $object_id );
			if ( ! $activity ) {
				return false;
			}
			$ctx = array(
				'instructor_person_id' => (int) $activity['instructor_person_id'],
				'is_enrolled'          => in_array( $object_id, Plugin::activities()->active_activity_ids( (int) $actor['id'] ), true ),
				'is_staff'             => 'asem_manage_event' === $ability && Plugin::activities()->is_staff( $object_id, (int) $actor['id'] ),
				'can_cash'             => 'asem_door_cash' === $ability && Plugin::activities()->staff_can_cash( $object_id, (int) $actor['id'] ),
				'is_entity_staff'      => 'asem_manage_event' === $ability && self::is_entity_staff( $user_id ),
				'is_treasurer'         => 'asem_door_cash' === $ability && self::is_treasurer( $user_id ),
			);
		}
		return self::decide( $ability, false, $actor, $ctx );
	}
}
