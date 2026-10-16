<?php
namespace Portale;

/**
 * Rimbalzo delle verifiche del plugin: il plugin parla solo con il portale, che gira la richiesta al server Wega
 * con il proprio token. Nel plugin non restano né l'indirizzo di Wega né alcuna credenziale.
 *
 * Il portale non fa da garante: la risposta di Wega (compresa l'attestazione firmata) passa invariata e il plugin
 * ne verifica la firma con le chiavi pubbliche di Wega, rilanciate da {@see LicenseProxy::jwks()}.
 */
final class LicenseProxy {

	const CACHE_TTL = 300;
	const JWKS_TTL  = 3600;

	/** @var Db */
	private $db;
	/** @var WegaClient */
	private $wega;

	public function __construct( Db $db, WegaClient $wega ) {
		$this->db   = $db;
		$this->wega = $wega;
	}

	/** @return array [codice HTTP, corpo JSON, intestazioni aggiuntive] */
	public function validate( string $rawBody, string $ip, ?int $now = null ): array {
		$now  = $now ?? time();
		$in   = json_decode( $rawBody, true );
		$key  = is_array( $in ) && isset( $in['license_key'] ) && is_string( $in['license_key'] ) ? trim( $in['license_key'] ) : '';
		$dom  = is_array( $in ) && isset( $in['domain'] ) && is_string( $in['domain'] ) ? Domain::normalize( $in['domain'] ) : '';
		if ( ! preg_match( '/^\S{8,200}$/', $key ) ) {
			return $this->error( 422, 'INVALID_REQUEST', 'Chiave mancante o non valida.' );
		}
		if ( '' === $dom ) {
			return $this->error( 422, 'INVALID_DOMAIN', 'Dominio mancante o non valido.' );
		}

		$hour = gmdate( 'YmdH', $now );
		if ( ! $this->allow( 'ip:' . hash( 'sha256', $ip ), $hour, Settings::limit( 'proxy_ip' ), $now ) || ! $this->allow( 'key:' . hash( 'sha256', $key ), $hour, Settings::limit( 'proxy_key' ), $now ) ) {
			Audit::log( $this->db, 'plugin', 'license.rate_limited', $dom );
			return $this->error( 429, 'RATE_LIMITED', 'Troppe richieste: riprova più tardi.', array( 'Retry-After: 3600' ) );
		}

		$ck  = hash( 'sha256', $key . '|' . $dom );
		$hit = $this->db->one( 'SELECT body, at FROM proxy_cache WHERE k = ?', array( $ck ) );
		if ( $hit && $now - (int) strtotime( $hit['at'] . ' UTC' ) < self::CACHE_TTL ) {
			return array( 200, (string) $hit['body'], array() );
		}

		try {
			$data = $this->wega->validate( $key, $dom );
		} catch ( WegaException $e ) {
			// Errori del client (4xx) si girano com'erano; rete e server diventano 502, così il plugin tiene lo stato precedente.
			if ( $e->httpStatus >= 400 && $e->httpStatus < 500 && 429 !== $e->httpStatus && 401 !== $e->httpStatus && 403 !== $e->httpStatus ) {
				return $this->error( $e->httpStatus, $e->errorCode, $e->getMessage() );
			}
			Audit::log( $this->db, 'plugin', 'license.upstream_error', $e->errorCode . ' ' . $e->httpStatus );
			return $this->error( 502, 'UPSTREAM', 'Server licenze non raggiungibile.' );
		}

		$body = (string) json_encode( $data );
		$this->db->run( 'DELETE FROM proxy_cache WHERE k = ?', array( $ck ) );
		$this->db->run( 'INSERT INTO proxy_cache (k, body, at) VALUES (?,?,?)', array( $ck, $body, gmdate( 'Y-m-d H:i:s', $now ) ) );
		return array( 200, $body, array() );
	}

	/** Chiavi pubbliche di Wega, in cache; se Wega non risponde si usa l'ultima copia. @return array [codice, corpo, intestazioni] */
	public function jwks( ?int $now = null ): array {
		$now = $now ?? time();
		$row = $this->db->one( "SELECT body, at FROM proxy_cache WHERE k = 'jwks'" );
		if ( $row && $now - (int) strtotime( $row['at'] . ' UTC' ) < self::JWKS_TTL ) {
			return array( 200, (string) $row['body'], array( 'Cache-Control: public, max-age=3600' ) );
		}
		list( $status, $raw ) = $this->wega->jwks();
		$ok = 200 === $status && is_array( json_decode( $raw, true ) ) && isset( json_decode( $raw, true )['keys'] );
		if ( $ok ) {
			$this->db->run( "DELETE FROM proxy_cache WHERE k = 'jwks'" );
			$this->db->run( "INSERT INTO proxy_cache (k, body, at) VALUES ('jwks',?,?)", array( $raw, gmdate( 'Y-m-d H:i:s', $now ) ) );
			return array( 200, $raw, array( 'Cache-Control: public, max-age=3600' ) );
		}
		if ( $row ) {
			return array( 200, (string) $row['body'], array( 'Cache-Control: public, max-age=300' ) );
		}
		return $this->error( 502, 'UPSTREAM', 'Chiavi non disponibili.' );
	}

	/** Conta una richiesta nella finestra oraria; false se supera il limite. */
	private function allow( string $bucket, string $hour, int $max, int $ref ): bool {
		$this->db->run( 'DELETE FROM rate_limits WHERE hour < ?', array( gmdate( 'YmdH', $ref - 7200 ) ) );
		$row = $this->db->one( 'SELECT n FROM rate_limits WHERE bucket = ? AND hour = ?', array( $bucket, $hour ) );
		if ( ! $row ) {
			$this->db->run( 'INSERT INTO rate_limits (bucket, hour, n) VALUES (?,?,1)', array( $bucket, $hour ) );
			return true;
		}
		if ( (int) $row['n'] >= $max ) {
			return false;
		}
		$this->db->run( 'UPDATE rate_limits SET n = n + 1 WHERE bucket = ? AND hour = ?', array( $bucket, $hour ) );
		return true;
	}

	private function error( int $status, string $code, string $message, array $headers = array() ): array {
		return array( $status, (string) json_encode( array( 'error' => array( 'code' => $code, 'message' => $message ) ) ), $headers );
	}
}
