<?php
namespace Portale;

/**
 * Confronto e importazione delle licenze del portale presenti sul server Wega (`GET /licenses`, scope `licenses:list`)
 * ma non nel database del portale. La chiave non viene mai restituita dall'elenco: le licenze importate non hanno la chiave
 * da mostrare al cliente (resta disponibile dal pannello Wega).
 */
final class WegaSync {

	const MAX_PAGES = 50;

	/** Tutte le licenze del portale su Wega. @return array[] */
	public static function fetchAll( WegaClient $wega ): array {
		$rows   = array();
		$cursor = null;
		for ( $i = 0; $i < self::MAX_PAGES; $i++ ) {
			list( $page, $cursor ) = $wega->listLicenses( $cursor );
			$rows = array_merge( $rows, $page );
			if ( null === $cursor ) {
				return $rows;
			}
		}
		throw new UserError( 'Troppe licenze da elencare in un colpo solo: filtra dal pannello Wega.' );
	}

	/**
	 * Per ogni licenza di Wega: `known` (già nel portale), `missing` (da importare) o `blocked` (dominio o dati non importabili, con motivo).
	 * @return array[] righe di Wega con `_state`, `_reason`, `_sub`
	 */
	public static function compare( Db $db, array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			$r['_sub']    = $db->one( 'SELECT * FROM subscriptions WHERE license_id = ?', array( (string) ( $r['id'] ?? '' ) ) );
			$r['_reason'] = '';
			$r['_state']  = 'known';
			$r['_diff']   = array();
			if ( $r['_sub'] ) {
				$r['_diff'] = self::differences( $r['_sub'], $r );
			}
			if ( ! $r['_sub'] ) {
				$reason       = self::problem( $db, $r );
				$r['_state']  = '' === $reason ? 'missing' : 'blocked';
				$r['_reason'] = $reason;
			}
			$out[] = $r;
		}
		return $out;
	}

	/** Differenze tra la licenza nel portale e quella su Wega (devono essere identiche). @return string[] */
	public static function differences( array $sub, array $w ): array {
		$d = array();
		if ( isset( $w['domain'] ) && Domain::normalize( (string) $w['domain'] ) !== $sub['domain'] ) {
			$d[] = 'dominio: Wega ' . $w['domain'];
		}
		if ( ! empty( $w['expires_at'] ) && ! empty( $sub['expires_at'] ) && abs( strtotime( (string) $w['expires_at'] ) - strtotime( $sub['expires_at'] . ' UTC' ) ) > 86400 ) {
			$d[] = 'scadenza: Wega ' . gmdate( 'd/m/Y', (int) strtotime( (string) $w['expires_at'] ) );
		}
		$wegaOff = in_array( strtoupper( (string) ( $w['state'] ?? '' ) ), array( 'SUSPENDED', 'REVOKED' ), true );
		if ( $wegaOff !== ( 'canceled' === $sub['status'] ) ) {
			$d[] = 'stato: Wega ' . strtoupper( (string) ( $w['state'] ?? '' ) );
		}
		return $d;
	}

	/** Licenze del portale con un id Wega che Wega non elenca (solo se l'elenco è completo). */
	public static function orphans( Db $db, array $rows ): array {
		$ids = array();
		foreach ( $rows as $r ) {
			$ids[ (string) ( $r['id'] ?? '' ) ] = true;
		}
		$out = array();
		foreach ( $db->all( "SELECT s.*, c.email FROM subscriptions s JOIN customers c ON c.id = s.customer_id WHERE s.license_id IS NOT NULL AND s.license_id <> ''" ) as $s ) {
			if ( ! isset( $ids[ $s['license_id'] ] ) ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	/** Codice del piano del portale per il nome piano di Wega, o null. */
	public static function planCode( string $planName ): ?string {
		$n = strtolower( trim( $planName ) );
		foreach ( (array) Config::get( 'plans', array() ) as $code => $p ) {
			if ( $n === strtolower( (string) ( $p['plan_name'] ?? '' ) ) || $n === strtolower( (string) ( $p['label'] ?? '' ) ) || $n === strtolower( (string) $code ) ) {
				return (string) $code;
			}
		}
		return null;
	}

	/** Motivo per cui la licenza non si può importare, o stringa vuota. */
	private static function problem( Db $db, array $r ): string {
		$email = strtolower( trim( (string) ( $r['customer_id'] ?? '' ) ) );
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return 'Il cliente («' . $email . '») non è un indirizzo email.';
		}
		$domain = Domain::normalize( (string) ( $r['domain'] ?? '' ) );
		if ( '' === $domain ) {
			return 'Dominio assente o non valido.';
		}
		if ( null === self::planCode( (string) ( $r['plan_name'] ?? '' ) ) ) {
			return 'Piano «' . (string) ( $r['plan_name'] ?? '' ) . '» sconosciuto al portale.';
		}
		$other = $db->one(
			"SELECT s.id FROM subscriptions s JOIN customers c ON c.id = s.customer_id WHERE s.domain = ? AND c.email <> ? AND s.status IN ('pending','active','past_due')",
			array( $domain, $email )
		);
		return $other ? 'Il dominio è già di un altro cliente nel portale.' : '';
	}

	/** Importa una licenza di Wega (senza chiave). @return array la riga inserita @throws UserError se non importabile */
	public static function import( Db $db, Billing $billing, array $r ): array {
		if ( $db->one( 'SELECT 1 AS x FROM subscriptions WHERE license_id = ?', array( (string) ( $r['id'] ?? '' ) ) ) ) {
			throw new UserError( 'Licenza già presente nel portale.' );
		}
		if ( $reason = self::problem( $db, $r ) ) {
			throw new UserError( $reason );
		}
		$cust  = $billing->customer( strtolower( trim( (string) $r['customer_id'] ) ) );
		$state = strtoupper( (string) ( $r['state'] ?? 'ACTIVE' ) );
		$map   = array( 'PENDING' => 'pending', 'SUSPENDED' => 'canceled', 'REVOKED' => 'canceled' );
		$exp   = ! empty( $r['expires_at'] ) ? strtotime( (string) $r['expires_at'] ) : false;
		$id    = Db::id();
		$db->run(
			'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, mode, license_id, expires_at, note, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
			array(
				$id, $cust['id'], self::planCode( (string) $r['plan_name'] ), 'manual', 'manual', $map[ $state ] ?? 'active', Domain::normalize( (string) $r['domain'] ), 'once',
				(string) $r['id'], $exp ? gmdate( 'Y-m-d H:i:s', $exp ) : null, 'Importata da Wega (' . $state . ')', Db::now(), Db::now(),
			)
		);
		return $billing->subscription( $id );
	}
}
