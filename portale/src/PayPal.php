<?php
namespace Portale;

/** PayPal Subscriptions: creazione dell'abbonamento, disdetta e verifica dei webhook. */
final class PayPal {

	private static function api(): string {
		return Config::get( 'paypal.live' ) ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
	}

	private static function token(): string {
		$auth = base64_encode( Config::get( 'paypal.client_id' ) . ':' . Config::get( 'paypal.secret' ) );
		list( $status, $raw ) = Http::send( 'POST', self::api() . '/v1/oauth2/token', array( 'Authorization: Basic ' . $auth, 'Content-Type: application/x-www-form-urlencoded' ), 'grant_type=client_credentials' );
		$d = json_decode( $raw, true );
		if ( 200 !== $status || empty( $d['access_token'] ) ) {
			throw new \RuntimeException( 'PayPal: accesso non riuscito.' );
		}
		return (string) $d['access_token'];
	}

	private static function call( string $method, string $path, ?array $body = null ): array {
		$headers = array( 'Authorization: Bearer ' . self::token(), 'Content-Type: application/json' );
		list( $status, $raw ) = Http::send( $method, self::api() . $path, $headers, null === $body ? null : (string) json_encode( $body ?: new \stdClass() ) );
		$d = json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 ) {
			throw new \RuntimeException( 'PayPal: richiesta non riuscita (' . $status . ').' );
		}
		return is_array( $d ) ? $d : array();
	}

	/** @return string indirizzo dove il cliente approva l'abbonamento */
	public static function approvalUrl( string $subscriptionId, string $email, string $planId ): string {
		$r = self::call( 'POST', '/v1/billing/subscriptions', array(
			'plan_id'             => $planId,
			'custom_id'           => $subscriptionId,
			'subscriber'          => array( 'email_address' => $email ),
			'application_context' => array(
				'brand_name'          => 'Associazione Semplice',
				'user_action'         => 'SUBSCRIBE_NOW',
				'return_url'          => Config::get( 'base_url' ) . '/grazie',
				'cancel_url'          => Config::get( 'base_url' ) . '/',
			),
		) );
		foreach ( (array) ( $r['links'] ?? array() ) as $l ) {
			if ( 'approve' === ( $l['rel'] ?? '' ) && ! empty( $l['href'] ) ) {
				return (string) $l['href'];
			}
		}
		throw new \RuntimeException( 'PayPal non ha restituito l\'indirizzo di approvazione.' );
	}

	/**
	 * Pagamento singolo: crea l'ordine e restituisce [id ordine, indirizzo di approvazione].
	 *
	 * @return array [string $orderId, string $approvalUrl]
	 */
	public static function createOrder( string $subscriptionId, int $cents, string $description ): array {
		$r = self::call( 'POST', '/v2/checkout/orders', array(
			'intent'              => 'CAPTURE',
			'purchase_units'      => array( array(
				'reference_id' => $subscriptionId,
				'custom_id'    => $subscriptionId,
				'description'  => mb_substr( $description, 0, 120 ),
				'amount'       => array( 'currency_code' => 'EUR', 'value' => number_format( $cents / 100, 2, '.', '' ) ),
			) ),
			'application_context' => array(
				'brand_name'  => 'Associazione Semplice',
				'user_action' => 'PAY_NOW',
				'return_url'  => Config::get( 'base_url' ) . '/paypal/ritorno',
				'cancel_url'  => Config::get( 'base_url' ) . '/',
			),
		) );
		foreach ( (array) ( $r['links'] ?? array() ) as $l ) {
			if ( in_array( $l['rel'] ?? '', array( 'approve', 'payer-action' ), true ) && ! empty( $l['href'] ) && ! empty( $r['id'] ) ) {
				return array( (string) $r['id'], (string) $l['href'] );
			}
		}
		throw new \RuntimeException( 'PayPal non ha restituito l\'indirizzo di approvazione.' );
	}

	/** Incassa un ordine approvato dal cliente. @return bool true se il pagamento è completato */
	public static function captureOrder( string $orderId ): bool {
		$r = self::call( 'POST', '/v2/checkout/orders/' . rawurlencode( $orderId ) . '/capture', array() );
		return 'COMPLETED' === ( $r['status'] ?? '' );
	}

	public static function cancel( string $paypalSubscription ): void {
		self::call( 'POST', '/v1/billing/subscriptions/' . rawurlencode( $paypalSubscription ) . '/cancel', array( 'reason' => 'Disdetta richiesta dal cliente' ) );
	}

	/** Verifica la firma con l'API di PayPal (verify-webhook-signature). @param array $headers intestazioni in minuscolo */
	public static function verify( string $payload, array $headers ): bool {
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || '' === (string) Config::get( 'paypal.webhook_id', '' ) ) {
			return false;
		}
		try {
			$r = self::call( 'POST', '/v1/notifications/verify-webhook-signature', array(
				'auth_algo'         => $headers['paypal-auth-algo'] ?? '',
				'cert_url'          => $headers['paypal-cert-url'] ?? '',
				'transmission_id'   => $headers['paypal-transmission-id'] ?? '',
				'transmission_sig'  => $headers['paypal-transmission-sig'] ?? '',
				'transmission_time' => $headers['paypal-transmission-time'] ?? '',
				'webhook_id'        => Config::get( 'paypal.webhook_id' ),
				'webhook_event'     => $event,
			) );
		} catch ( \RuntimeException $e ) {
			return false;
		}
		return 'SUCCESS' === ( $r['verification_status'] ?? '' );
	}
}
