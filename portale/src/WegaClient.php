<?php
namespace Portale;

/** Client dell'API Wega License v1 (solo backend: il token è un segreto). */
final class WegaClient {

	/** @var string */
	private $base;
	/** @var string */
	private $token;

	public function __construct( string $baseUrl, string $token ) {
		$this->base  = rtrim( $baseUrl, '/' );
		$this->token = $token;
	}

	/** Chiede una chiave. `external_ref` rende la richiesta idempotente. @return array dati con license_key, license_id, expires_at… */
	public function createLicense( array $params ): array {
		return $this->request( 'POST', '/licenses', $params )['data'];
	}

	/** Registra un rinnovo: aggiunge duration_days alla scadenza. */
	public function renew( string $licenseKey, ?string $renewedAt = null ): array {
		$body = array( 'license_key' => $licenseKey );
		if ( null !== $renewedAt ) {
			$body['renewed_at'] = $renewedAt;
		}
		return $this->request( 'POST', '/licenses/renew', $body )['data'];
	}

	/** Una licenza non valida risponde 200: guarda `valid` e `reason_code`. */
	public function validate( string $licenseKey, ?string $domain = null ): array {
		$body = array( 'license_key' => $licenseKey );
		if ( null !== $domain ) {
			$body['domain'] = $domain;
		}
		return $this->request( 'POST', '/licenses/validate', $body );
	}

	public function show( string $licenseId ): array {
		return $this->request( 'GET', '/licenses/' . rawurlencode( $licenseId ) )['data'];
	}

	/** Cambia il dominio (tipo 3); max 3 cambi in 30 giorni; il vecchio dominio smette di funzionare al controllo successivo. */
	public function changeDomain( string $licenseId, string $licenseKey, string $newDomain ): array {
		return $this->request( 'PUT', '/licenses/' . rawurlencode( $licenseId ) . '/binding', array( 'license_key' => $licenseKey, 'identity' => $newDomain ) )['data'];
	}

	/** Operazioni di stato sulla licenza (scope licenses:manage). */
	public function suspend( string $licenseId ): array {
		return $this->request( 'POST', '/licenses/' . rawurlencode( $licenseId ) . '/suspend', array( 'reason' => 'portale' ) );
	}

	public function restore( string $licenseId ): array {
		return $this->request( 'POST', '/licenses/' . rawurlencode( $licenseId ) . '/restore', array( 'reason' => 'portale' ) );
	}

	public function revoke( string $licenseId ): array {
		return $this->request( 'POST', '/licenses/' . rawurlencode( $licenseId ) . '/revoke', array( 'reason' => 'portale' ) );
	}

	/** Chiave di una licenza già emessa (scope licenses:reveal-key, ogni lettura è registrata da Wega): serve a ripristinare il portale. */
	public function licenseKey( string $licenseId ): string {
		$r = $this->request( 'GET', '/licenses/' . rawurlencode( $licenseId ) . '/key' );
		return (string) ( $r['data']['license_key'] ?? '' );
	}

	/** Modifica campi della licenza (customer_id, plan_name, expires_at, duration_days, domain…; scope licenses:manage). */
	public function patch( string $licenseId, array $fields ): array {
		return $this->request( 'PATCH', '/licenses/' . rawurlencode( $licenseId ), $fields );
	}

	/** Elenco delle licenze del portale (richiede lo scope licenses:list). @return array [elementi, cursore successivo o null] */
	public function listLicenses( ?string $cursor = null, int $limit = 100 ): array {
		$q = '?limit=' . max( 1, min( 200, $limit ) ) . ( null !== $cursor ? '&cursor=' . rawurlencode( $cursor ) : '' );
		$r = $this->request( 'GET', '/licenses' . $q );
		return array( (array) ( $r['data'] ?? array() ), isset( $r['next_cursor'] ) && '' !== $r['next_cursor'] ? (string) $r['next_cursor'] : null );
	}

	/** Chiavi pubbliche di Wega (nessun token: sono pubbliche). @return array [codice HTTP, corpo] */
	public function jwks(): array {
		return Http::withRetry( 'GET', preg_replace( '#/api/v1/?$#', '', $this->base ) . '/.well-known/jwks.json', array( 'Accept: application/json' ), null, 2 );
	}

	private function request( string $method, string $path, ?array $body = null ): array {
		$headers = array( 'Authorization: Bearer ' . $this->token, 'Accept: application/json', 'Content-Type: application/json' );
		// Una POST /licenses è idempotente (external_ref) e il rinnovo non lo è: il rinnovo non si ripete su 5xx.
		$tries = '/licenses/renew' === $path ? 1 : 3;
		list( $status, $raw ) = Http::withRetry( $method, $this->base . $path, $headers, null === $body ? null : (string) json_encode( $body ), $tries );
		$data = json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			$err = is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
			throw new WegaException(
				(string) ( $err['message'] ?? 'Server licenze non raggiungibile.' ),
				(string) ( $err['code'] ?? ( 0 === $status ? 'NETWORK' : 'ERROR' ) ),
				$status
			);
		}
		return $data;
	}
}
