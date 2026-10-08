<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Polizze dell'associazione: responsabilità civile verso terzi, infortuni dei soci e altre coperture generali.
 * Lo stato di ogni tipo (in regola, in scadenza, scaduta, mancante) è quello della polizza che copre di più.
 */
final class AssocPolicies {

	const RC       = 'rc';
	const ACCIDENT = 'accident';
	const OTHER    = 'other';

	public static function kinds(): array {
		return array( self::RC => 'Responsabilità civile', self::ACCIDENT => 'Infortuni dei soci', self::OTHER => 'Altra copertura' );
	}

	private static function db(): \wpdb {
		return Db::db();
	}

	private static function date( $v ): ?string {
		$v  = trim( (string) $v );
		$dt = \DateTime::createFromFormat( 'Y-m-d', $v );
		return $dt && $dt->format( 'Y-m-d' ) === $v ? $v : null;
	}

	/** @throws \InvalidArgumentException */
	public static function add( string $kind, string $company, string $policy_no, string $from, string $to, string $premium = '', string $coverage = '' ): int {
		if ( ! isset( self::kinds()[ $kind ] ) ) {
			throw new \InvalidArgumentException( 'Scegli il tipo di polizza.' );
		}
		$company = trim( sanitize_text_field( $company ) );
		if ( '' === $company ) {
			throw new \InvalidArgumentException( 'Indica la compagnia assicurativa.' );
		}
		$f = self::date( $from );
		$t = self::date( $to );
		if ( ! $f || ! $t ) {
			throw new \InvalidArgumentException( 'Indica le date di inizio e di fine della copertura.' );
		}
		if ( $t < $f ) {
			throw new \InvalidArgumentException( 'La copertura non può finire prima di iniziare.' );
		}
		$premium = trim( $premium );
		$cents   = '' === $premium ? null : Money::parse( $premium );
		if ( '' !== $premium && null === $cents ) {
			throw new \InvalidArgumentException( 'Il premio non è un importo valido.' );
		}
		$ok = self::db()->insert(
			Db::t( 'assoc_policies' ),
			array(
				'kind'          => $kind,
				'company'       => mb_substr( $company, 0, 120 ),
				'policy_no'     => mb_substr( trim( sanitize_text_field( $policy_no ) ), 0, 80 ),
				'valid_from'    => $f,
				'valid_to'      => $t,
				'premium_cents' => $cents,
				'coverage'      => mb_substr( trim( sanitize_text_field( $coverage ) ), 0, 255 ),
				'created_at'    => Db::now(),
			)
		);
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'Impossibile salvare la polizza (errore del database).' );
		}
		$id = (int) self::db()->insert_id;
		Audit::log( 'policy.added', 'policy', $id, array( 'kind' => $kind, 'to' => $t ) );
		return $id;
	}

	public static function delete( int $id ): void {
		$row = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'assoc_policies' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Polizza non trovata.' );
		}
		self::db()->delete( Db::t( 'assoc_policies' ), array( 'id' => $id ) );
		Audit::log( 'policy.deleted', 'policy', $id, array( 'kind' => $row['kind'] ) );
	}

	/** Tutte le polizze, per tipo e poi dalla più recente. @return array[] */
	public static function all(): array {
		return self::db()->get_results( 'SELECT * FROM ' . Db::t( 'assoc_policies' ) . ' ORDER BY kind, valid_to DESC, id DESC', ARRAY_A ) ?: array();
	}

	/** Stato di ogni tipo di polizza (anche se non ce n'è nessuna). @return array<string,array{policy:?array,status:string}> */
	public static function statuses( ?string $today = null ): array {
		$today = $today ?: Db::today();
		$out   = array();
		foreach ( array_keys( self::kinds() ) as $k ) {
			$pol = self::db()->get_row(
				self::db()->prepare( 'SELECT * FROM ' . Db::t( 'assoc_policies' ) . ' WHERE kind = %s AND valid_from <= %s ORDER BY valid_to DESC, id DESC LIMIT 1', $k, $today ),
				ARRAY_A
			);
			$out[ $k ] = array( 'policy' => $pol ?: null, 'status' => Insurance::status_of( $pol ?: null, $today ) );
		}
		return $out;
	}

	/** La responsabilità civile è l'unica copertura che conta per gli avvisi: scoperta, scaduta o in scadenza? */
	public static function rc_status( ?string $today = null ): string {
		return self::statuses( $today )[ self::RC ]['status'];
	}
}
