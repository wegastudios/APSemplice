<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Configurazione dei pagamenti online: WooCommerce oppure, in alternativa, Stripe o PayPal direttamente.
 * Questa parte è solo CONFIGURAZIONE e verifica (pura, senza rete): l'incasso vero è un passo successivo.
 */
final class PaymentConfig {

	const NONE        = 'none';
	const WOOCOMMERCE = 'woocommerce';
	const STRIPE      = 'stripe';
	const PAYPAL      = 'paypal';

	public static function providers(): array {
		return array(
			self::NONE        => 'Nessuno (pagamento in sede)',
			self::WOOCOMMERCE => 'WooCommerce (non ancora collegato)',
			self::STRIPE      => 'Stripe (alternativa a WooCommerce)',
			self::PAYPAL      => 'PayPal (alternativa a WooCommerce)',
		);
	}

	public static function is_valid( string $provider ): bool {
		return isset( self::providers()[ $provider ] );
	}

	/**
	 * Controlla la configurazione del gateway scelto.
	 *
	 * @param array $c payment_provider, stripe_mode (test|live), stripe_publishable_key, stripe_secret_key, stripe_webhook_secret,
	 *                 paypal_mode (sandbox|live), paypal_client_id, paypal_client_secret   (segreti in chiaro)
	 * @return array ['errors' => string[], 'warnings' => string[]]
	 */
	public static function validate( array $c ): array {
		$errors   = array();
		$warnings = array();
		$provider = (string) ( $c['payment_provider'] ?? self::NONE );
		if ( ! self::is_valid( $provider ) ) {
			return array( 'errors' => array( 'Gateway di pagamento non valido.' ), 'warnings' => array() );
		}
		if ( self::STRIPE === $provider ) {
			$mode = 'live' === ( $c['stripe_mode'] ?? 'test' ) ? 'live' : 'test';
			$pk   = trim( (string) ( $c['stripe_publishable_key'] ?? '' ) );
			$sk   = trim( (string) ( $c['stripe_secret_key'] ?? '' ) );
			$wh   = trim( (string) ( $c['stripe_webhook_secret'] ?? '' ) );
			if ( '' === $pk ) {
				$errors[] = 'Stripe: manca la chiave pubblicabile (pk_…).';
			} elseif ( ! preg_match( '/^pk_(test|live)_[A-Za-z0-9]+$/', $pk ) ) {
				$errors[] = 'Stripe: la chiave pubblicabile deve iniziare con pk_test_ o pk_live_.';
			} elseif ( 0 !== strpos( $pk, 'pk_' . $mode . '_' ) ) {
				$errors[] = 'Stripe: la chiave pubblicabile non corrisponde alla modalità ' . ( 'live' === $mode ? 'reale (live)' : 'di prova (test)' ) . '.';
			}
			if ( '' === $sk ) {
				$errors[] = 'Stripe: manca la chiave segreta (sk_… o rk_…).';
			} elseif ( ! preg_match( '/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $sk ) ) {
				$errors[] = 'Stripe: la chiave segreta deve iniziare con sk_test_, sk_live_ (o rk_ per le chiavi con restrizioni).';
			} elseif ( ! preg_match( '/^(sk|rk)_' . $mode . '_/', $sk ) ) {
				$errors[] = 'Stripe: la chiave segreta non corrisponde alla modalità ' . ( 'live' === $mode ? 'reale (live)' : 'di prova (test)' ) . '.';
			}
			if ( '' === $wh ) {
				$warnings[] = 'Stripe: manca il segreto del webhook (whsec_…): senza non si potranno confermare i pagamenti in automatico.';
			} elseif ( 0 !== strpos( $wh, 'whsec_' ) ) {
				$errors[] = 'Stripe: il segreto del webhook deve iniziare con whsec_.';
			}
			if ( 'live' === $mode && empty( $errors ) ) {
				$warnings[] = 'Stripe è in modalità REALE: i pagamenti saranno veri.';
			}
		}
		if ( self::PAYPAL === $provider ) {
			$mode = 'live' === ( $c['paypal_mode'] ?? 'sandbox' ) ? 'live' : 'sandbox';
			$id   = trim( (string) ( $c['paypal_client_id'] ?? '' ) );
			$sec  = trim( (string) ( $c['paypal_client_secret'] ?? '' ) );
			if ( '' === $id ) {
				$errors[] = 'PayPal: manca il Client ID.';
			} elseif ( ! preg_match( '/^[A-Za-z0-9_\-]{20,}$/', $id ) ) {
				$errors[] = 'PayPal: il Client ID non sembra valido.';
			}
			if ( '' === $sec ) {
				$errors[] = 'PayPal: manca il Client Secret.';
			} elseif ( ! preg_match( '/^[A-Za-z0-9_\-]{20,}$/', $sec ) ) {
				$errors[] = 'PayPal: il Client Secret non sembra valido.';
			}
			if ( 'live' === $mode && empty( $errors ) ) {
				$warnings[] = 'PayPal è in modalità REALE: i pagamenti saranno veri.';
			}
		}
		if ( self::WOOCOMMERCE === $provider ) {
			$warnings[] = 'WooCommerce: l\'integrazione non è ancora attiva; per ora i pagamenti restano in sede.';
		}
		return array( 'errors' => $errors, 'warnings' => $warnings );
	}
}
