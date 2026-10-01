<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'aps_settings';

	/** Chiavi segrete: nel database restano cifrate; si possono anche definire come costanti in wp-config.php (APS_<NOME>). */
	const SECRET_KEYS = array( 'stripe_secret_key', 'stripe_webhook_secret', 'paypal_client_secret' );

	/** Chiavi non segrete dei gateway: anche queste possono essere costanti in wp-config.php. */
	const GATEWAY_KEYS = array( 'stripe_mode', 'stripe_publishable_key', 'paypal_mode', 'paypal_client_id' );

	public static function defaults(): array {
		return array(
			'association_name'        => '',
			'tax_code'                => '',
			'social_year_start_month' => 9,     // l'anno sociale inizia a settembre
			'membership_fee_cents'    => 1000,  // quota associativa proposta negli incassi
			'founder_years'           => 99,    // durata della tessera del socio fondatore
			'member_area_page_id'     => 0,     // pagina del sito con l'area riservata (shortcode); 0 = home
			'license_key'             => '',    // chiave di licenza (verifica in standby, vedi License)
			'cancel_policy_default'   => CancelPolicy::H48, // termine predefinito per annullare gli eventi cancellabili
			'payment_provider'        => PaymentConfig::NONE,
			'stripe_mode'             => 'test',
			'stripe_publishable_key'  => '',
			'stripe_secret_key'       => '',   // cifrata
			'stripe_webhook_secret'   => '',   // cifrata
			'paypal_mode'             => 'sandbox',
			'paypal_client_id'        => '',
			'paypal_client_secret'    => '',   // cifrata
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Salva le impostazioni. Per le chiavi segrete: valore vuoto = lascia quella che c'è; valore nuovo = lo cifra.
	 * (Per toglierne una si usa {@see Settings::clear_secret()}.)
	 */
	public static function update( array $values ): void {
		$clean = self::all();
		foreach ( self::defaults() as $k => $_ ) {
			if ( ! array_key_exists( $k, $values ) ) {
				continue;
			}
			if ( in_array( $k, self::SECRET_KEYS, true ) ) {
				$plain = trim( (string) $values[ $k ] );
				if ( '' !== $plain ) {
					$clean[ $k ] = Secrets::is_encrypted( $plain ) ? $plain : Secrets::encrypt( $plain, wp_salt( 'auth' ) );
				}
				continue;
			}
			$clean[ $k ] = $values[ $k ];
		}
		$clean['social_year_start_month'] = max( 1, min( 12, (int) $clean['social_year_start_month'] ) );
		$clean['membership_fee_cents']    = max( 0, (int) $clean['membership_fee_cents'] );
		$clean['founder_years']           = max( 1, (int) $clean['founder_years'] );
		$clean['member_area_page_id']     = max( 0, (int) $clean['member_area_page_id'] );
		$clean['license_key']             = substr( trim( (string) $clean['license_key'] ), 0, 120 );
		$clean['cancel_policy_default']   = CancelPolicy::is_valid( (string) $clean['cancel_policy_default'] ) ? (string) $clean['cancel_policy_default'] : CancelPolicy::H48;
		$clean['payment_provider']        = PaymentConfig::is_valid( (string) $clean['payment_provider'] ) ? (string) $clean['payment_provider'] : PaymentConfig::NONE;
		$clean['stripe_mode']             = 'live' === $clean['stripe_mode'] ? 'live' : 'test';
		$clean['paypal_mode']             = 'live' === $clean['paypal_mode'] ? 'live' : 'sandbox';
		foreach ( array( 'stripe_publishable_key', 'paypal_client_id' ) as $k ) {
			$clean[ $k ] = substr( trim( (string) $clean[ $k ] ), 0, 200 );
		}
		Audit::log( 'settings.updated', 'settings' ); // senza i valori: nel registro non finiscono chiavi
		update_option( self::OPTION, $clean );
	}

	public static function clear_secret( string $key ): void {
		if ( ! in_array( $key, self::SECRET_KEYS, true ) ) {
			return;
		}
		$all         = self::all();
		$all[ $key ] = '';
		update_option( self::OPTION, $all );
		Audit::log( 'settings.secret_cleared', 'settings', null, array( 'key' => $key ) );
	}

	/** Nome della costante di wp-config.php che sostituisce un'impostazione, es. APS_STRIPE_SECRET_KEY. */
	public static function constant_name( string $key ): string {
		return 'APS_' . strtoupper( $key );
	}

	public static function is_constant( string $key ): bool {
		return defined( self::constant_name( $key ) );
	}

	/** Chiave segreta in chiaro (costante in wp-config.php, altrimenti decifrata dal database). Stringa vuota se manca o illeggibile. */
	public static function secret( string $key ): string {
		if ( self::is_constant( $key ) ) {
			return (string) constant( self::constant_name( $key ) );
		}
		$plain = Secrets::decrypt( (string) self::get( $key ), wp_salt( 'auth' ) );
		return null === $plain ? '' : $plain;
	}

	/** True se c'è una chiave salvata (anche se non leggibile). */
	public static function has_secret( string $key ): bool {
		return self::is_constant( $key ) || '' !== (string) self::get( $key );
	}

	/** Configurazione dei pagamenti con i segreti in chiaro (solo per uso interno: mai da stampare). */
	public static function payment_config(): array {
		$c = array( 'payment_provider' => (string) self::get( 'payment_provider' ) );
		foreach ( self::GATEWAY_KEYS as $k ) {
			$c[ $k ] = self::is_constant( $k ) ? (string) constant( self::constant_name( $k ) ) : (string) self::get( $k );
		}
		foreach ( self::SECRET_KEYS as $k ) {
			$c[ $k ] = self::secret( $k );
		}
		return $c;
	}

	public static function start_month(): int {
		return (int) self::get( 'social_year_start_month' );
	}

	public static function social_year( ?string $date = null ): SocialYear {
		return SocialYear::for_date( $date ?? Db::today(), self::start_month() );
	}
}
