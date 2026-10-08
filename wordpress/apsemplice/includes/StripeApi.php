<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe Checkout: l'utente paga su una pagina ospitata da Stripe, noi non vediamo mai i dati della carta.
 * Le chiamate di rete sono iniettate ($http) così tutto si prova senza rete.
 *
 * $http: function( string $method, string $url, array $headers, ?string $body ): array{code:int, body:string}
 */
final class StripeApi {

	const BASE = 'https://api.stripe.com';

	/** Parametri per creare la sessione di pagamento (da inviare con http_build_query). */
	public static function checkout_params( array $items, string $public_id, string $payer_email, string $success_url, string $cancel_url ): array {
		$lines = array();
		foreach ( array_values( $items ) as $idx => $i ) {
			$lines[ $idx ] = array(
				'quantity'   => 1,
				'price_data' => array(
					'currency'     => 'eur',
					'unit_amount'  => (int) $i['amount_cents'],
					'product_data' => array( 'name' => PaymentItems::line_name( $i ) ),
				),
			);
		}
		$params = array(
			'mode'                => 'payment',
			'locale'              => 'it',
			'success_url'         => $success_url,
			'cancel_url'          => $cancel_url,
			'client_reference_id' => $public_id,
			'line_items'          => $lines,
			'metadata'            => array( 'apse_payment' => $public_id ),
			'payment_intent_data' => array( 'metadata' => array( 'apse_payment' => $public_id ) ),
		);
		if ( '' !== $payer_email ) {
			$params['customer_email'] = $payer_email;
		}
		return $params;
	}

	private static function call( callable $http, string $method, string $path, string $secret, ?array $form = null, array $extra = array() ): array {
		$headers = array_merge( array( 'Authorization' => 'Bearer ' . $secret ), $extra );
		$body    = null;
		if ( null !== $form ) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			$body                    = http_build_query( $form, '', '&', PHP_QUERY_RFC3986 );
		}
		$r    = $http( $method, self::BASE . $path, $headers, $body );
		$data = json_decode( (string) $r['body'], true );
		if ( (int) $r['code'] < 200 || (int) $r['code'] >= 300 || ! is_array( $data ) ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'risposta ' . (int) $r['code'];
			throw new \RuntimeException( 'Stripe: ' . $msg );
		}
		return $data;
	}

	/** @return array ['id' => cs_…, 'url' => pagina di pagamento] */
	public static function create_session( callable $http, string $secret, array $params, string $idempotency_key ): array {
		$d = self::call( $http, 'POST', '/v1/checkout/sessions', $secret, $params, array( 'Idempotency-Key' => $idempotency_key ) );
		if ( empty( $d['id'] ) || empty( $d['url'] ) ) {
			throw new \RuntimeException( 'Stripe: risposta senza indirizzo di pagamento.' );
		}
		return array( 'id' => (string) $d['id'], 'url' => (string) $d['url'] );
	}

	public static function retrieve_session( callable $http, string $secret, string $session_id ): array {
		return self::call( $http, 'GET', '/v1/checkout/sessions/' . rawurlencode( $session_id ), $secret );
	}

	public static function is_paid( array $session ): bool {
		return 'paid' === ( $session['payment_status'] ?? '' );
	}

	/** Importo incassato in centesimi di euro. Con una valuta diversa dall'euro vale 0: il pagamento va controllato a mano. */
	public static function paid_eur_cents( array $session ): int {
		if ( isset( $session['currency'] ) && 'eur' !== strtolower( (string) $session['currency'] ) ) {
			return 0;
		}
		return max( 0, (int) ( $session['amount_total'] ?? 0 ) );
	}
}
