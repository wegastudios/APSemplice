<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APS_TESTS' ) || exit;

/**
 * Chi può vedere un contenuto (pagina, articolo, o un pezzo di pagina).
 *
 *  - public      tutti;
 *  - members     soci con tessera valida (fondatore, ordinario, volontario);
 *  - volunteers  soci e volontari con tessera valida;
 *  - activity    iscritti/prenotati a una delle attività scelte (e chi le tiene).
 *
 * Gli amministratori vedono sempre tutto. Con la licenza non in regola solo i contenuti pubblici restano
 * visibili ai non amministratori. Logica pura: i dati arrivano in $ctx.
 */
final class Visibility {

	const PUBLIC_    = 'public';
	const MEMBERS    = 'members';
	const VOLUNTEERS = 'volunteers';
	const ACTIVITY   = 'activity';

	public static function labels(): array {
		return array(
			self::PUBLIC_    => 'Pubblico (tutti)',
			self::MEMBERS    => 'Solo soci (tessera valida)',
			self::VOLUNTEERS => 'Solo soci e volontari',
			self::ACTIVITY   => 'Solo iscritti a specifiche attività',
		);
	}

	public static function is_valid( string $rule ): bool {
		return isset( self::labels()[ $rule ] );
	}

	/**
	 * @param string $rule
	 * @param array  $ctx is_admin, logged_in, member_area_allowed, active_member, person_type,
	 *                    required_activity_ids, my_activity_ids (a cui partecipa), taught_activity_ids (che tiene)
	 */
	public static function decide( string $rule, array $ctx ): bool {
		if ( self::PUBLIC_ === $rule ) {
			return true;
		}
		if ( ! empty( $ctx['is_admin'] ) ) {
			return true;
		}
		if ( empty( $ctx['logged_in'] ) || empty( $ctx['member_area_allowed'] ) ) {
			return false;
		}
		$active = ! empty( $ctx['active_member'] );
		switch ( $rule ) {
			case self::MEMBERS:
				return $active;
			case self::VOLUNTEERS:
				return $active && MemberType::VOLUNTEER === ( $ctx['person_type'] ?? null );
			case self::ACTIVITY:
				$required = array_map( 'intval', (array) ( $ctx['required_activity_ids'] ?? array() ) );
				if ( ! $required ) {
					return $active; // nessuna attività scelta: come "solo soci"
				}
				$mine = array_map( 'intval', array_merge( (array) ( $ctx['my_activity_ids'] ?? array() ), (array) ( $ctx['taught_activity_ids'] ?? array() ) ) );
				return (bool) array_intersect( $required, $mine );
		}
		return false;
	}

	/** Perché è negato (per scegliere il messaggio): 'login' | 'license' | 'rule'. */
	public static function denial_reason( array $ctx ): string {
		if ( empty( $ctx['logged_in'] ) ) {
			return 'login';
		}
		if ( empty( $ctx['member_area_allowed'] ) ) {
			return 'license';
		}
		return 'rule';
	}

	/** Etichetta breve per elenchi (colonna "Accesso"). */
	public static function short_label( string $rule, array $activity_names = array() ): string {
		switch ( $rule ) {
			case self::MEMBERS:
				return 'Solo soci';
			case self::VOLUNTEERS:
				return 'Solo volontari';
			case self::ACTIVITY:
				return $activity_names ? 'Iscritti: ' . implode( ', ', $activity_names ) : 'Solo soci';
		}
		return 'Pubblico';
	}
}
