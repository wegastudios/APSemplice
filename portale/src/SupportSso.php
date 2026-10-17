<?php
namespace Portale;

/**
 * Accesso unico al sito di supporto: il portale conferma chi è la persona (ha già fatto l'accesso qui) e la manda al sito
 * di supporto con un token firmato e a vita brevissima. Il sito di supporto verifica la firma con lo stesso segreto.
 *
 * Token: base64url(payload JSON) . "." . base64url(HMAC-SHA256(payload_base64url, segreto))
 * Payload: { v:1, iss: base_url del portale, aud: url del sito di supporto, sub: email, role: admin|support|customer,
 *            iat, exp (iat + 60 s), jti: id casuale da usare una sola volta }
 * Indirizzo: {support.url}/sso?t=<token>
 */
final class SupportSso {

	const TTL = 60;

	public static function supportUrl(): string {
		return rtrim( (string) Config::get( 'support.url', 'https://supporto.associazionesemplice.it' ), '/' );
	}

	public static function enabled(): bool {
		return strlen( (string) Config::get( 'support.sso_secret', '' ) ) >= 32;
	}

	/** Ruolo da dichiarare al sito di supporto: lo staff col suo ruolo, tutti gli altri come clienti. */
	public static function roleFor( ?Db $db, string $email ): string {
		return Staff::role( $db, $email ) ?? 'customer';
	}

	public static function token( string $email, string $role, ?int $now = null ): string {
		$now     = $now ?? time();
		$payload = self::b64( (string) json_encode( array(
			'v'    => 1,
			'iss'  => rtrim( (string) Config::get( 'base_url' ), '/' ),
			'aud'  => self::supportUrl(),
			'sub'  => strtolower( trim( $email ) ),
			'role' => $role,
			'iat'  => $now,
			'exp'  => $now + self::TTL,
			'jti'  => bin2hex( random_bytes( 16 ) ),
		) ) );
		return $payload . '.' . self::b64( hash_hmac( 'sha256', $payload, (string) Config::get( 'support.sso_secret', '' ), true ) );
	}

	/** Indirizzo a cui mandare la persona: con l'accesso unico configurato porta il token, altrimenti la home del supporto. */
	public static function url( ?Db $db, string $email ): string {
		if ( ! self::enabled() ) {
			return self::supportUrl();
		}
		return self::supportUrl() . '/sso?t=' . rawurlencode( self::token( $email, self::roleFor( $db, $email ) ) );
	}

	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
