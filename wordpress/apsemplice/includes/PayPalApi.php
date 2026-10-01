<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * PayPal Orders v2: l'utente approva il pagamento su PayPal, poi il sito "cattura" l'ordine.
 * Le chiamate di rete sono iniettate ($http) così tutto si prova senza rete.
 *
 * $http: function( string $method, string $url, array $headers, ?string $body ): array{code:int, body:string}
 */
final class PayPalApi {

	/** json_encode senza dipendere da WordPress (così la classe si prova anche senza). */
	private static function json( $v ): string {
		$s = json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false === $s ? '{}' : $s;
	}

	public static function base( bool $live ): string {
		return $live ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
	}

	private static function request( callable $http, string $method, string $url, array $headers, ?string $body ): array {
		$r = $http( $method, $url, $headers, $body );
		return array( 'code' => (int) $r['code'], 'data' => json_decode( (string) $r['body'], true ) );
	}

	private static function fail( array $res, string $what ): \RuntimeException {
		$d   = $res['data'];
		$msg = is_array( $d ) ? (string) ( $d['message'] ?? ( $d['error_description'] ?? '' ) ) : '';
		return new \RuntimeException( 'PayPal (' . $what . '): ' . ( '' !== $msg ? $msg : 'risposta ' . $res['code'] ) );
	}

	public static function access_token( callable $http, bool $live, string $client_id, string $secret ): string {
		$res = self::request(
			$http,
			'POST',
			self::base( $live ) . '/v1/oauth2/token',
			array( 'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ), 'Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json' ),
			'grant_type=client_credentials'
		);
		if ( 200 !== $res['code'] || empty( $res['data']['access_token'] ) ) {
			throw self::fail( $res, 'accesso' );
		}
		return (string) $res['data']['access_token'];
	}

	public static function order_payload( array $items, string $public_id, string $return_url, string $cancel_url, string $brand ): array {
		$lines = array();
		$total = 0;
		foreach ( $items as $i ) {
			$cents   = (int) $i['amount_cents'];
			$total  += $cents;
			$lines[] = array(
				'name'        => PaymentItems::line_name( $i ),
				'quantity'    => '1',
				'unit_amount' => array( 'currency_code' => 'EUR', 'value' => PaymentItems::decimal( $cents ) ),
			);
		}
		$value = PaymentItems::decimal( $total );
		return array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'reference_id' => $public_id,
					'custom_id'    => $public_id,
					'description'  => PaymentItems::summary( $items, $brand ),
					'amount'       => array(
						'currency_code' => 'EUR',
						'value'         => $value,
						'breakdown'     => array( 'item_total' => array( 'currency_code' => 'EUR', 'value' => $value ) ),
					),
					'items'        => $lines,
				),
			),
			'payment_source' => array(
				'paypal' => array(
					'experience_context' => array(
						'brand_name'  => mb_substr( '' !== $brand ? $brand : 'APSemplice', 0, 120 ),
						'locale'      => 'it-IT',
						'user_action' => 'PAY_NOW',
						'return_url'  => $return_url,
						'cancel_url'  => $cancel_url,
					),
				),
			),
		);
	}

	/** @return array ['id' => ordine, 'url' => pagina PayPal dove approvare] */
	public static function create_order( callable $http, bool $live, string $token, array $payload, string $request_id ): array {
		$res = self::request(
			$http,
			'POST',
			self::base( $live ) . '/v2/checkout/orders',
			array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'PayPal-Request-Id' => $request_id, 'Prefer' => 'return=representation' ),
			self::json( $payload )
		);
		if ( ! in_array( $res['code'], array( 200, 201 ), true ) || empty( $res['data']['id'] ) ) {
			throw self::fail( $res, 'ordine' );
		}
		$url = '';
		foreach ( (array) ( $res['data']['links'] ?? array() ) as $l ) {
			if ( in_array( $l['rel'] ?? '', array( 'payer-action', 'approve' ), true ) ) {
				$url = (string) $l['href'];
				break;
			}
		}
		if ( '' === $url ) {
			throw new \RuntimeException( 'PayPal: risposta senza indirizzo di approvazione.' );
		}
		return array( 'id' => (string) $res['data']['id'], 'url' => $url );
	}

	public static function get_order( callable $http, bool $live, string $token, string $order_id ): array {
		$res = self::request( $http, 'GET', self::base( $live ) . '/v2/checkout/orders/' . rawurlencode( $order_id ), array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ), null );
		if ( 200 !== $res['code'] || ! is_array( $res['data'] ) ) {
			throw self::fail( $res, 'lettura ordine' );
		}
		return $res['data'];
	}

	/** Cattura l'ordine approvato. Se era già stato catturato lo rilegge. @return array l'ordine */
	public static function capture_order( callable $http, bool $live, string $token, string $order_id ): array {
		$res = self::request(
			$http,
			'POST',
			self::base( $live ) . '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture',
			array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'PayPal-Request-Id' => 'capture-' . $order_id ),
			'{}'
		);
		if ( in_array( $res['code'], array( 200, 201 ), true ) && is_array( $res['data'] ) ) {
			return $res['data'];
		}
		if ( 422 === $res['code'] && false !== strpos( self::json( $res['data'] ), 'ORDER_ALREADY_CAPTURED' ) ) {
			return self::get_order( $http, $live, $token, $order_id );
		}
		throw self::fail( $res, 'cattura' );
	}

	public static function is_completed( array $order ): bool {
		return 'COMPLETED' === ( $order['status'] ?? '' ) && self::captured_cents( $order ) > 0;
	}

	/** Importo realmente catturato (somma delle catture completate), in centesimi. */
	public static function captured_cents( array $order ): int {
		$sum = 0;
		foreach ( (array) ( $order['purchase_units'] ?? array() ) as $u ) {
			foreach ( (array) ( $u['payments']['captures'] ?? array() ) as $c ) {
				if ( 'COMPLETED' === ( $c['status'] ?? '' ) ) {
					$sum += (int) round( (float) ( $c['amount']['value'] ?? 0 ) * 100 );
				}
			}
		}
		return $sum;
	}

	public static function capture_id( array $order ): string {
		foreach ( (array) ( $order['purchase_units'] ?? array() ) as $u ) {
			foreach ( (array) ( $u['payments']['captures'] ?? array() ) as $c ) {
				if ( ! empty( $c['id'] ) ) {
					return (string) $c['id'];
				}
			}
		}
		return '';
	}
}
