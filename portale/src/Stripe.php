<?php
namespace Portale;

/** Stripe: abbonamenti con Checkout, portale di fatturazione, annullamento e verifica dei webhook. */
final class Stripe {

	const API = 'https://api.stripe.com/v1';

	/** @return string indirizzo del pagamento ospitato da Stripe */
	public static function checkoutUrl( string $subscriptionId, string $email, string $priceId ): string {
		$r = self::call( 'POST', '/checkout/sessions', array(
			'mode'                                 => 'subscription',
			'line_items[0][price]'                 => $priceId,
			'line_items[0][quantity]'              => 1,
			'customer_email'                       => $email,
			'client_reference_id'                  => $subscriptionId,
			'subscription_data[metadata][sub_id]'  => $subscriptionId,
			'success_url'                          => Config::get( 'base_url' ) . '/grazie',
			'cancel_url'                           => Config::get( 'base_url' ) . '/',
		) );
		if ( empty( $r['url'] ) ) {
			throw new \RuntimeException( 'Stripe non ha restituito l\'indirizzo di pagamento.' );
		}
		return (string) $r['url'];
	}

	public static function portalUrl( string $stripeCustomer ): string {
		$r = self::call( 'POST', '/billing_portal/sessions', array( 'customer' => $stripeCustomer, 'return_url' => Config::get( 'base_url' ) . '/account' ) );
		return (string) ( $r['url'] ?? '' );
	}

	/** Disdetta a fine periodo: l'abbonamento resta attivo fino alla scadenza già pagata. */
	public static function cancelAtPeriodEnd( string $stripeSubscription ): void {
		self::call( 'POST', '/subscriptions/' . rawurlencode( $stripeSubscription ), array( 'cancel_at_period_end' => 'true' ) );
	}

	/**
	 * Verifica l'intestazione Stripe-Signature (HMAC-SHA256 su "t.corpo", tolleranza 5 minuti).
	 *
	 * @param int|null $now solo per i test
	 */
	public static function verify( string $payload, string $header, string $secret, ?int $now = null ): bool {
		$t   = null;
		$sig = array();
		foreach ( explode( ',', $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$t = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$sig[] = $kv[1];
			}
		}
		if ( null === $t || ! $sig || '' === $secret || abs( ( $now ?? time() ) - $t ) > 300 ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $t . '.' . $payload, $secret );
		foreach ( $sig as $s ) {
			if ( hash_equals( $expected, $s ) ) {
				return true;
			}
		}
		return false;
	}

	private static function call( string $method, string $path, array $form ): array {
		$headers = array( 'Authorization: Bearer ' . Config::get( 'stripe.secret_key' ), 'Content-Type: application/x-www-form-urlencoded' );
		list( $status, $raw ) = Http::send( $method, self::API . $path, $headers, http_build_query( $form ) );
		$data = json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			throw new \RuntimeException( 'Stripe: richiesta non riuscita (' . $status . ').' );
		}
		return $data;
	}
}
