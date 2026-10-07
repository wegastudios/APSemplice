<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Configurazione dei pagamenti online: nessuno, WooCommerce oppure Stripe, PayPal o entrambi insieme.
 * Questa parte è solo CONFIGURAZIONE e verifica (pura, senza rete): l'incasso vero è un passo successivo.
 */
final class PaymentConfig {

	const NONE        = 'none';
	const WOOCOMMERCE = 'woocommerce';
	const STRIPE      = 'stripe';
	const PAYPAL      = 'paypal';
	const BOTH        = 'stripe_paypal';

	/** Diciture predefinite dei pulsanti e delle note: si possono personalizzare da Impostazioni → Pagamenti online. */
	const DEFAULT_LABELS = array(
		self::STRIPE      => 'Paga con carta',
		self::PAYPAL      => 'Paga con PayPal',
		self::WOOCOMMERCE => 'Paga nel negozio',
	);

	public static function providers(): array {
		return array(
			self::NONE        => 'Nessuno (pagamento in sede o con bonifico)',
			self::WOOCOMMERCE => 'WooCommerce (negozio del sito)',
			self::STRIPE      => 'Stripe (carta)',
			self::PAYPAL      => 'PayPal',
			self::BOTH        => 'Stripe e PayPal insieme',
		);
	}

	public static function is_valid( string $provider ): bool {
		return isset( self::providers()[ $provider ] );
	}

	/** Gateway diretti previsti dalla scelta: stripe e/o paypal (WooCommerce e «nessuno» non ne hanno). @return string[] */
	public static function gateways_of( string $provider ): array {
		switch ( $provider ) {
			case self::STRIPE:
				return array( self::STRIPE );
			case self::PAYPAL:
				return array( self::PAYPAL );
			case self::BOTH:
				return array( self::STRIPE, self::PAYPAL );
		}
		return array();
	}

	/**
	 * Controlla la configurazione del gateway scelto (o di entrambi).
	 *
	 * @param array $c payment_provider, stripe_mode (test|live), stripe_publishable_key, stripe_secret_key, stripe_webhook_secret,
	 *                 paypal_mode (sandbox|live), paypal_client_id, paypal_client_secret   (segreti in chiaro),
	 *                 site_https (0|1, facoltativo: se 0 la modalità reale è rifiutata)
	 * @return array ['errors' => string[], 'warnings' => string[]]
	 */
	public static function validate( array $c ): array {
		$provider = (string) ( $c['payment_provider'] ?? self::NONE );
		if ( ! self::is_valid( $provider ) ) {
			return array( 'errors' => array( 'Gateway di pagamento non valido.' ), 'warnings' => array() );
		}
		$errors   = array();
		$warnings = array();
		foreach ( self::gateways_of( $provider ) as $g ) {
			$r        = self::validate_gateway( $c, $g );
			$errors   = array_merge( $errors, $r['errors'] );
			$warnings = array_merge( $warnings, $r['warnings'] );
		}
		if ( self::WOOCOMMERCE === $provider ) {
			$warnings[] = 'WooCommerce: collega le quote ai prodotti in Impostazioni → Tecniche → Integrazioni; le voci senza prodotto non si possono pagare online. Il negozio deve usare l\'euro.';
		}
		return array( 'errors' => $errors, 'warnings' => $warnings );
	}

	/** Controllo di un solo gateway diretto (stripe o paypal). @return array ['errors' => string[], 'warnings' => string[]] */
	public static function validate_gateway( array $c, string $gateway ): array {
		$errors   = array();
		$warnings = array();
		$https    = ! array_key_exists( 'site_https', $c ) || ! empty( $c['site_https'] );
		if ( self::STRIPE === $gateway ) {
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
				$warnings[] = 'Stripe: manca il segreto del webhook (whsec_…): senza di esso i pagamenti non possono essere confermati automaticamente.';
			} elseif ( 0 !== strpos( $wh, 'whsec_' ) ) {
				$errors[] = 'Stripe: il segreto del webhook deve iniziare con whsec_.';
			}
			if ( 'live' === $mode && ! $https ) {
				$errors[] = 'Stripe in modalità reale richiede che il sito sia servito in https: oggi l\'indirizzo del sito è http. Attiva https e aggiorna l\'indirizzo del sito, oppure usa la modalità di prova.';
			}
			if ( 'live' === $mode && empty( $errors ) ) {
				$warnings[] = 'Stripe è in modalità REALE: i pagamenti saranno veri.';
			}
		}
		if ( self::PAYPAL === $gateway ) {
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
			if ( 'live' === $mode && ! $https ) {
				$errors[] = 'PayPal in modalità reale richiede che il sito sia servito in https: oggi l\'indirizzo del sito è http. Attiva https e aggiorna l\'indirizzo del sito, oppure usa la modalità sandbox.';
			}
			if ( 'live' === $mode && empty( $errors ) ) {
				$warnings[] = 'PayPal è in modalità REALE: i pagamenti saranno veri.';
			}
		}
		return array( 'errors' => $errors, 'warnings' => $warnings );
	}

	/** Dicitura del pulsante di un gateway (personalizzata o predefinita). @param array $settings impostazioni (Settings::all()) */
	public static function label( string $gateway, array $settings = array() ): string {
		$v = trim( (string) ( $settings[ 'pay_label_' . $gateway ] ?? '' ) );
		return '' !== $v ? $v : (string) ( self::DEFAULT_LABELS[ $gateway ] ?? '' );
	}

	/** Nota sotto il pulsante di un gateway (personalizzata o predefinita). */
	public static function note( string $gateway, array $settings = array() ): string {
		$v = trim( (string) ( $settings[ 'pay_note_' . $gateway ] ?? '' ) );
		if ( '' !== $v ) {
			return $v;
		}
		switch ( $gateway ) {
			case self::STRIPE:
				return 'Paghi su una pagina sicura di Stripe: i dati della carta non passano da questo sito.';
			case self::PAYPAL:
				return 'Paghi su una pagina sicura di PayPal: i dati della carta non passano da questo sito.';
			case self::WOOCOMMERCE:
				return 'Completi il pagamento nel negozio del sito: appena risulta pagato lo registriamo.';
		}
		return '';
	}
}
