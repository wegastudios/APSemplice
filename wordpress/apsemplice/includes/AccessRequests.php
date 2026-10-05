<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Richieste di "Primo accesso" fatte col cellulare da soci senza email: la segreteria le vede nel riepilogo
 * e risponde con il link di attivazione su WhatsApp.
 */
final class AccessRequests {

	const OPTION = 'apse_access_requests';
	const MAX    = 200;

	/** @return array<int,int> persona => quando (timestamp) */
	public static function all(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function add( int $person_id ): void {
		$all               = self::all();
		$all[ $person_id ] = time();
		arsort( $all );
		update_option( self::OPTION, array_slice( $all, 0, self::MAX, true ), false );
	}

	public static function remove( int $person_id ): void {
		$all = self::all();
		if ( isset( $all[ $person_id ] ) ) {
			unset( $all[ $person_id ] );
			update_option( self::OPTION, $all, false );
		}
	}

	/** Richieste ancora da evadere, con la persona (quelle di chi nel frattempo si è attivato spariscono). @return array[] persona + 'requested_at' */
	public static function pending(): array {
		$out = array();
		foreach ( self::all() as $pid => $at ) {
			$p = Plugin::people()->get( (int) $pid );
			if ( ! $p || ! empty( $p['wp_user_id'] ) ) {
				self::remove( (int) $pid );
				continue;
			}
			$p['requested_at'] = (int) $at;
			$out[]             = $p;
		}
		return $out;
	}
}
