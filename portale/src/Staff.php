<?php
namespace Portale;

/**
 * Staff del portale. Due ruoli:
 * - admin: gestione del portale e del supporto (gli indirizzi in `admins` di config.php sono sempre admin e non si tolgono da qui);
 * - support: solo la parte amministrativa del sito di supporto, nessun accesso alla gestione del portale.
 * Entrano come gli altri utenti (link per email o password): il ruolo si legge ogni volta dall'email.
 */
final class Staff {

	const ADMIN   = 'admin';
	const SUPPORT = 'support';

	const LABELS = array( self::ADMIN => 'Amministratore', self::SUPPORT => 'Solo supporto' );

	/** @return string|null admin, support oppure null se l'indirizzo non è dello staff */
	public static function role( ?Db $db, string $email ): ?string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return null;
		}
		foreach ( (array) Config::get( 'admins', array() ) as $a ) {
			if ( strtolower( trim( (string) $a ) ) === $email ) {
				return self::ADMIN;
			}
		}
		if ( $db ) {
			$row = $db->one( 'SELECT role FROM staff WHERE email = ?', array( $email ) );
			if ( $row && isset( self::LABELS[ $row['role'] ] ) ) {
				return (string) $row['role'];
			}
		}
		return null;
	}

	/** Elenco unico: prima gli admin di config.php (non modificabili), poi quelli del database. */
	public static function all( Db $db ): array {
		$out = array();
		foreach ( (array) Config::get( 'admins', array() ) as $a ) {
			$a = strtolower( trim( (string) $a ) );
			if ( '' !== $a ) {
				$out[ $a ] = array( 'email' => $a, 'role' => self::ADMIN, 'locked' => true, 'created_at' => '' );
			}
		}
		foreach ( $db->all( 'SELECT email, role, created_at FROM staff ORDER BY email' ) as $r ) {
			if ( ! isset( $out[ $r['email'] ] ) ) {
				$out[ $r['email'] ] = array( 'email' => $r['email'], 'role' => $r['role'], 'locked' => false, 'created_at' => $r['created_at'] );
			}
		}
		return array_values( $out );
	}

	/**
	 * Aggiunge o cambia il ruolo. Crea anche il cliente con quell'email, così la persona può entrare dal link per email.
	 * @throws UserError
	 */
	public static function set( Db $db, Billing $billing, string $email, string $role ): string {
		$email = strtolower( trim( $email ) );
		if ( ! isset( self::LABELS[ $role ] ) ) {
			throw new UserError( 'Ruolo non valido.' );
		}
		$billing->customer( $email ); // valida l'indirizzo e crea il cliente se manca
		foreach ( (array) Config::get( 'admins', array() ) as $a ) {
			if ( strtolower( trim( (string) $a ) ) === $email ) {
				throw new UserError( 'Questo indirizzo è amministratore da config.php: si cambia solo lì.' );
			}
		}
		if ( $db->one( 'SELECT 1 AS x FROM staff WHERE email = ?', array( $email ) ) ) {
			$db->run( 'UPDATE staff SET role = ? WHERE email = ?', array( $role, $email ) );
		} else {
			$db->run( 'INSERT INTO staff (email, role, created_at) VALUES (?,?,?)', array( $email, $role, Db::now() ) );
		}
		return $email;
	}

	/** @throws UserError */
	public static function remove( Db $db, string $email ): void {
		$email = strtolower( trim( $email ) );
		if ( $db->one( 'SELECT 1 AS x FROM staff WHERE email = ?', array( $email ) ) ) {
			$db->run( 'DELETE FROM staff WHERE email = ?', array( $email ) );
			return;
		}
		throw new UserError( 'Indirizzo non presente nello staff (o amministratore da config.php).' );
	}
}
