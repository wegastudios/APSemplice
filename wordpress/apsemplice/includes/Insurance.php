<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Assicurazione dei volontari (obbligatoria per chi svolge attività in modo continuativo): polizze per persona con validità,
 * e stato per ogni volontario (in regola, in scadenza, scaduta, mancante).
 */
final class Insurance {

	const VALID    = 'valid';
	const EXPIRING = 'expiring';
	const EXPIRED  = 'expired';
	const NONE     = 'none';
	const SOON     = 30; // giorni

	public static function status_labels(): array {
		return array( self::VALID => 'In regola', self::EXPIRING => 'In scadenza', self::EXPIRED => 'Scaduta', self::NONE => 'Nessuna polizza' );
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
	public static function add( int $person_id, string $company, string $policy_no, string $from, string $to, string $notes = '' ): int {
		$p = Plugin::people()->get( $person_id );
		if ( ! $p || ! MemberType::is_member( $p['type'] ) ) {
			throw new \InvalidArgumentException( 'La polizza si registra per un socio.' );
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
		$ok = self::db()->insert(
			Db::t( 'insurance' ),
			array(
				'person_id'  => $person_id,
				'company'    => mb_substr( $company, 0, 120 ),
				'policy_no'  => mb_substr( trim( sanitize_text_field( $policy_no ) ), 0, 80 ),
				'valid_from' => $f,
				'valid_to'   => $t,
				'notes'      => mb_substr( trim( sanitize_text_field( $notes ) ), 0, 255 ),
				'created_at' => Db::now(),
			)
		);
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'Impossibile salvare la polizza (errore del database).' );
		}
		$id = (int) self::db()->insert_id;
		Audit::log( 'insurance.added', 'person', $person_id, array( 'policy' => $id, 'to' => $t ) );
		return $id;
	}

	public static function delete( int $id ): void {
		$row = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'insurance' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Polizza non trovata.' );
		}
		self::db()->delete( Db::t( 'insurance' ), array( 'id' => $id ) );
		Audit::log( 'insurance.deleted', 'person', (int) $row['person_id'], array( 'policy' => $id ) );
	}

	/** Polizze di una persona, dalla più recente. @return array[] */
	public static function for_person( int $person_id ): array {
		return self::db()->get_results( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'insurance' ) . ' WHERE person_id = %d ORDER BY valid_to DESC, id DESC', $person_id ), ARRAY_A ) ?: array();
	}

	/** Stato di copertura a una data, data la polizza con la fine più lontana. */
	public static function status_of( ?array $latest, string $today ): string {
		if ( ! $latest ) {
			return self::NONE;
		}
		if ( $latest['valid_from'] > $today ) {
			return self::NONE; // copertura non ancora iniziata
		}
		if ( $latest['valid_to'] < $today ) {
			return self::EXPIRED;
		}
		$soon = ( new \DateTimeImmutable( $today ) )->modify( '+' . self::SOON . ' days' )->format( 'Y-m-d' );
		return $latest['valid_to'] <= $soon ? self::EXPIRING : self::VALID;
	}

	/**
	 * Registro dei volontari: i soci e volontari non cessati, con la polizza che copre di più e il suo stato.
	 *
	 * @return array[] ogni riga: person (riga people), policy (o null), status
	 */
	public static function register( ?string $today = null ): array {
		$today = $today ?: Db::today();
		$rows  = self::db()->get_results(
			self::db()->prepare( 'SELECT * FROM ' . Db::t( 'people' ) . ' WHERE deleted_at IS NULL AND anonymized_at IS NULL AND left_on IS NULL AND type = %s ORDER BY last_name, first_name', MemberType::VOLUNTEER ),
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $p ) {
			$pol = self::db()->get_row(
				self::db()->prepare( 'SELECT * FROM ' . Db::t( 'insurance' ) . ' WHERE person_id = %d AND valid_from <= %s ORDER BY valid_to DESC, id DESC LIMIT 1', (int) $p['id'], $today ),
				ARRAY_A
			);
			$out[] = array( 'person' => $p, 'policy' => $pol ?: null, 'status' => self::status_of( $pol ?: null, $today ) );
		}
		return $out;
	}

	/** Volontari senza copertura valida (mancante o scaduta) o in scadenza: serve per gli avvisi in Bacheca. @return array<string,int> */
	public static function counts( ?string $today = null ): array {
		$c = array( self::VALID => 0, self::EXPIRING => 0, self::EXPIRED => 0, self::NONE => 0 );
		foreach ( self::register( $today ) as $r ) {
			$c[ $r['status'] ]++;
		}
		return $c;
	}
}
