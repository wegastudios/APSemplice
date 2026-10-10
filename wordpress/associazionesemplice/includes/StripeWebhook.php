<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Verifica della firma dei webhook di Stripe (intestazione Stripe-Signature: "t=…,v1=…"). */
final class StripeWebhook {

	const TOLERANCE_SECONDS = 300;

	/** Firma attesa per un contenuto e un istante: serve anche ai test per costruire una richiesta valida. */
	public static function sign( string $payload, string $secret, int $timestamp ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
	}

	public static function header( string $payload, string $secret, int $timestamp ): string {
		return 't=' . $timestamp . ',v1=' . self::sign( $payload, $secret, $timestamp );
	}

	/**
	 * @param string $payload   corpo grezzo della richiesta, esattamente come arriva
	 * @param string $header    valore di Stripe-Signature
	 * @param string $secret    segreto del webhook (whsec_…)
	 * @param int    $now       istante attuale (parametro per i test)
	 */
	public static function verify( string $payload, string $header, string $secret, int $now, int $tolerance = self::TOLERANCE_SECONDS ): bool {
		if ( '' === $secret || '' === $header ) {
			return false;
		}
		$timestamp  = null;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$timestamp = ctype_digit( $kv[1] ) ? (int) $kv[1] : null;
			} elseif ( 'v1' === $kv[0] ) {
				$signatures[] = $kv[1];
			}
		}
		if ( null === $timestamp || ! $signatures || abs( $now - $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = self::sign( $payload, $secret, $timestamp );
		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}
		return false;
	}
}
