<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APS_TESTS' ) || exit;

/**
 * Chi può fare cosa. I permessi NON derivano da ruoli WordPress ma dai dati:
 *
 *  - amministratore del sito (capability `aps_manage`): tutto;
 *  - "socio e volontario": solo sulle attività di cui è l'istruttore;
 *  - socio: solo sui propri dati.
 *
 * La regola è in {@see Access::decide()} (pura, testata). Le "capability meta" sono registrate
 * in WordPress, quindi ovunque si può scrivere `current_user_can( 'aps_notify_activity', $activity_id )`.
 */
final class Access {

	/** Capability meta => ammesse da decide(). Il primo argomento di current_user_can è l'id dell'oggetto. */
	const ABILITIES = array(
		'aps_view_person',        // id persona: vedere la scheda
		'aps_edit_own_profile',   // id persona: modificare i propri dati
		'aps_view_payments',      // id persona: vedere i propri pagamenti
		'aps_add_guest',          // id persona (il socio ospitante): aggiungere un ospite
		'aps_view_activity',      // id attività: vedere i dati base
		'aps_view_participants',  // id attività: vedere chi è iscritto
		'aps_notify_activity',    // id attività: inviare un avviso ufficiale agli iscritti
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
			case 'aps_view_person':
			case 'aps_edit_own_profile':
			case 'aps_view_payments':
				return (int) ( $ctx['person_id'] ?? 0 ) === $me;
			case 'aps_add_guest':
				return (int) ( $ctx['person_id'] ?? 0 ) === $me && MemberType::is_member( (string) $actor['type'] );
			case 'aps_view_activity':
				return self::is_instructor( $actor, $ctx ) || ! empty( $ctx['is_enrolled'] );
			case 'aps_view_participants':
			case 'aps_notify_activity':
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

	public static function is_admin_user( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, Plugin::CAP );
	}

	public static function user_can( int $user_id, string $ability, int $object_id ): bool {
		$is_admin = self::is_admin_user( $user_id );
		if ( $is_admin ) {
			return true;
		}
		// Licenza non in regola: l'accesso di soci e volontari è sospeso (gli amministratori restano, coperti dal popup).
		if ( ! License::allows( 'member_area' ) ) {
			return false;
		}
		$actor = self::person_for_user( $user_id );
		if ( ! $actor ) {
			return false;
		}
		$ctx = array( 'person_id' => $object_id );
		if ( in_array( $ability, array( 'aps_view_activity', 'aps_view_participants', 'aps_notify_activity' ), true ) ) {
			$activity = Plugin::activities()->get( $object_id );
			if ( ! $activity ) {
				return false;
			}
			$ctx = array(
				'instructor_person_id' => (int) $activity['instructor_person_id'],
				'is_enrolled'          => in_array( $object_id, Plugin::activities()->active_activity_ids( (int) $actor['id'] ), true ),
			);
		}
		return self::decide( $ability, false, $actor, $ctx );
	}
}
