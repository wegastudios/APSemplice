<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Prova di connessione ai gateway con le chiavi salvate: parte solo quando l'amministratore preme "Verifica connessione".
 * Non muove denaro: Stripe → lettura del saldo; PayPal → richiesta del token di accesso.
 * La chiamata di rete è iniettata ($http) così la logica si prova senza rete.
 */
final class Gateways {

	/**
	 * @param string   $provider PaymentConfig::STRIPE | PAYPAL
	 * @param array    $c        configurazione con i segreti in chiaro
	 * @param callable $http     function( string $method, string $url, array $headers, ?string $body ): array{code:int, body:string}
	 * @return array ['ok'=>bool, 'message'=>string]
	 */
	public static function test( string $provider, array $c, callable $http ): array {
		try {
			if ( PaymentConfig::STRIPE === $provider ) {
				return self::test_stripe( $c, $http );
			}
			if ( PaymentConfig::PAYPAL === $provider ) {
				return self::test_paypal( $c, $http );
			}
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'message' => 'Connessione non riuscita: ' . $e->getMessage() );
		}
		return array( 'ok' => false, 'message' => 'Scegli Stripe o PayPal per fare la prova.' );
	}

	private static function test_stripe( array $c, callable $http ): array {
		$sk = trim( (string) ( $c['stripe_secret_key'] ?? '' ) );
		if ( '' === $sk ) {
			return array( 'ok' => false, 'message' => 'Inserisci prima la chiave segreta di Stripe.' );
		}
		$r    = $http( 'GET', 'https://api.stripe.com/v1/balance', array( 'Authorization' => 'Bearer ' . $sk ), null );
		$data = json_decode( (string) $r['body'], true );
		if ( 200 === (int) $r['code'] ) {
			$live = is_array( $data ) && ! empty( $data['livemode'] );
			$mode = 'live' === ( $c['stripe_mode'] ?? 'test' ) ? 'live' : 'test';
			if ( $live !== ( 'live' === $mode ) ) {
				return array( 'ok' => false, 'message' => 'La chiave funziona ma è ' . ( $live ? 'REALE (live)' : 'di PROVA (test)' ) . ', mentre la modalità scelta è ' . ( 'live' === $mode ? 'reale' : 'di prova' ) . '.' );
			}
			return array( 'ok' => true, 'message' => 'Stripe: connessione riuscita (' . ( $live ? 'modalità reale' : 'modalità di prova' ) . ').' );
		}
		if ( 401 === (int) $r['code'] ) {
			return array( 'ok' => false, 'message' => 'Stripe: la chiave segreta non è valida.' );
		}
		$err = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'risposta ' . (int) $r['code'];
		return array( 'ok' => false, 'message' => 'Stripe: ' . $err );
	}

	private static function test_paypal( array $c, callable $http ): array {
		$id  = trim( (string) ( $c['paypal_client_id'] ?? '' ) );
		$sec = trim( (string) ( $c['paypal_client_secret'] ?? '' ) );
		if ( '' === $id || '' === $sec ) {
			return array( 'ok' => false, 'message' => 'Inserisci Client ID e Client Secret di PayPal.' );
		}
		$live = 'live' === ( $c['paypal_mode'] ?? 'sandbox' );
		$url  = ( $live ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com' ) . '/v1/oauth2/token';
		$r    = $http(
			'POST',
			$url,
			array( 'Authorization' => 'Basic ' . base64_encode( $id . ':' . $sec ), 'Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json' ),
			'grant_type=client_credentials'
		);
		$data = json_decode( (string) $r['body'], true );
		if ( 200 === (int) $r['code'] && is_array( $data ) && ! empty( $data['access_token'] ) ) {
			return array( 'ok' => true, 'message' => 'PayPal: connessione riuscita (' . ( $live ? 'modalità reale' : 'sandbox' ) . ').' );
		}
		if ( in_array( (int) $r['code'], array( 400, 401 ), true ) ) {
			return array( 'ok' => false, 'message' => 'PayPal: Client ID o Client Secret non validi per la modalità ' . ( $live ? 'reale' : 'sandbox' ) . '.' );
		}
		return array( 'ok' => false, 'message' => 'PayPal: risposta inattesa (' . (int) $r['code'] . ').' );
	}

	/** Chiamata di rete reale, tramite l'API HTTP di WordPress. */
	public static function wp_http( string $method, string $url, array $headers, ?string $body ): array {
		$res = wp_remote_request( $url, array( 'method' => $method, 'headers' => $headers, 'body' => $body, 'timeout' => 15 ) );
		if ( is_wp_error( $res ) ) {
			throw new \RuntimeException( $res->get_error_message() );
		}
		return array( 'code' => (int) wp_remote_retrieve_response_code( $res ), 'body' => (string) wp_remote_retrieve_body( $res ) );
	}
}
