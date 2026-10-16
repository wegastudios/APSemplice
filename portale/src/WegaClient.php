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
