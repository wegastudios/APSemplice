<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Coda della segreteria per il "Primo accesso":
 *  - unknown: chi ha scritto non è stato riconosciuto tra i soci (né per email né per cellulare);
 *  - change:  un socio con accesso già attivo chiede di usare un'altra email (si approva a mano);
 *  - review:  accesso attivato riconoscendo il socio solo dal cellulare (da controllare).
 * Ogni voce porta i dati inseriti (nome, email, cellulare) e, se riconosciuto, la persona.
 */
final class AccessRequests {

	const OPTION = 'apse_access_requests';
	const MAX    = 200;
	const KINDS  = array( 'unknown', 'change', 'review' );

	/** @return array<string,array> id => voce */
	public static function all(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * @param array $d kind, person_id (facoltativo), name, email, phone
	 * @return string id della voce (una nuova richiesta uguale sostituisce la precedente)
	 */
	public static function add( array $d ): string {
		$kind = in_array( $d['kind'] ?? '', self::KINDS, true ) ? $d['kind'] : 'unknown';
		$item = array(
			'kind' => $kind, 'person_id' => (int) ( $d['person_id'] ?? 0 ), 'name' => mb_substr( (string) ( $d['name'] ?? '' ), 0, 120 ),
			'email' => substr( (string) ( $d['email'] ?? '' ), 0, 190 ), 'phone' => substr( (string) ( $d['phone'] ?? '' ), 0, 40 ), 'at' => time(),
		);
		$id  = substr( md5( $kind . '|' . $item['person_id'] . '|' . Text::lower( $item['email'] ) . '|' . Phone::key( $item['phone'] ) ), 0, 12 );
		$all = self::all();
		$all[ $id ] = $item;
		uasort( $all, function ( $a, $b ) {
			return $b['at'] <=> $a['at'];
		} );
		update_option( self::OPTION, array_slice( $all, 0, self::MAX, true ), false );
		return $id;
	}

	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	public static function remove( string $id ): void {
		$all = self::all();
		if ( isset( $all[ $id ] ) ) {
			unset( $all[ $id ] );
			update_option( self::OPTION, $all, false );
		}
	}

	/** Voci ancora da evadere, con la persona (se riconosciuta). @return array[] voce + 'id' + 'person' */
	public static function pending(): array {
		$out = array();
		foreach ( self::all() as $id => $it ) {
			$it['id']     = (string) $id;
			$it['person'] = $it['person_id'] ? Plugin::people()->get( (int) $it['person_id'] ) : null;
			if ( 'unknown' !== $it['kind'] && ! $it['person'] ) {
				self::remove( (string) $id );
				continue;
			}
			$out[] = $it;
		}
		return $out;
	}
}
