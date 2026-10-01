<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/** Tutte le impostazioni si cambiano dal pannello (menu APSemplice → Impostazioni): nessun file da modificare. */
final class Settings {

	const OPTION = 'apse_settings';

	/** Chiavi segrete: nel database restano cifrate e non vengono mai mostrate. */
	const SECRET_KEYS = array( 'stripe_secret_key', 'stripe_webhook_secret', 'paypal_client_secret' );

	const DEFAULT_PAYMENT_HINT = 'Il pagamento si effettua in sede presso la segreteria.';

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
			'accent_color'            => '',    // colore d'accento del front-end; vuoto = quello del tema
			'payment_hint'            => self::DEFAULT_PAYMENT_HINT, // testo mostrato ai soci che hanno importi da pagare
			'gate_message'            => '',    // messaggio sui contenuti riservati; vuoto = automatico
			'payment_provider'        => PaymentConfig::NONE,
			'stripe_mode'             => 'test',
			'stripe_publishable_key'  => '',
			'stripe_secret_key'       => '',   // cifrata
			'stripe_webhook_secret'   => '',   // cifrata
			'paypal_mode'             => 'sandbox',
			'paypal_client_id'        => '',
			'paypal_client_secret'    => '',   // cifrata
			'card_qr_enabled'         => 0,     // QR sulla tessera digitale: a scelta del gestore, spento di default
			'wpai_default_type'       => 'ordinary', // import da WP All Import: tipo socio se manca la colonna
			'wpai_default_account_id' => 0,         // ... e conto della prima nota se manca
			'wpai_keep_balances'      => 1,         // ... non cambiare i saldi attuali dei conti
			'wpai_mark_members'       => 0,         // ... segna i soci come iscritti all'anno sociale corrente
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
		$clean['accent_color']            = Color::normalize( (string) $clean['accent_color'] );
		$clean['payment_hint']            = '' === trim( (string) $clean['payment_hint'] ) ? self::DEFAULT_PAYMENT_HINT : substr( trim( (string) $clean['payment_hint'] ), 0, 300 );
		$clean['gate_message']            = substr( trim( (string) $clean['gate_message'] ), 0, 200 );
		$clean['payment_provider']        = PaymentConfig::is_valid( (string) $clean['payment_provider'] ) ? (string) $clean['payment_provider'] : PaymentConfig::NONE;
		$clean['stripe_mode']             = 'live' === $clean['stripe_mode'] ? 'live' : 'test';
		$clean['paypal_mode']             = 'live' === $clean['paypal_mode'] ? 'live' : 'sandbox';
		$clean['wpai_default_type']       = MemberType::is_member( (string) $clean['wpai_default_type'] ) ? (string) $clean['wpai_default_type'] : MemberType::ORDINARY;
		$clean['wpai_default_account_id'] = max( 0, (int) $clean['wpai_default_account_id'] );
		$clean['wpai_keep_balances']      = empty( $clean['wpai_keep_balances'] ) ? 0 : 1;
		$clean['card_qr_enabled']         = empty( $clean['card_qr_enabled'] ) ? 0 : 1;
		$clean['wpai_mark_members']       = empty( $clean['wpai_mark_members'] ) ? 0 : 1;
		foreach ( array( 'stripe_publishable_key', 'paypal_client_id' ) as $k ) {
			$clean[ $k ] = substr( trim( (string) $clean[ $k ] ), 0, 200 );
		}
		Audit::log( 'settings.updated', 'settings' ); // senza i valori: nel registro non finiscono chiavi
		update_option( self::OPTION, $clean );
	}

	// ---------- QR della tessera ----------

	const CARD_SALT_OPTION = 'apse_card_salt';

	/** Segreto che firma i QR delle tessere: legato al sito e a un "sale" che l'amministratore può rigenerare (invalida tutti i QR in circolazione). */
	public static function card_secret(): string {
		$salt = (string) get_option( self::CARD_SALT_OPTION, '' );
		if ( '' === $salt ) {
			$salt = bin2hex( random_bytes( 16 ) );
			update_option( self::CARD_SALT_OPTION, $salt, false );
		}
		return wp_salt( 'auth' ) . '|' . $salt;
	}

	public static function regenerate_card_salt(): void {
		update_option( self::CARD_SALT_OPTION, bin2hex( random_bytes( 16 ) ), false );
		Audit::log( 'card.qr_regenerated', 'settings' );
	}

	public static function card_qr_enabled(): bool {
		return ! empty( self::get( 'card_qr_enabled' ) );
	}

	/** Indirizzo di verifica del biglietto di una prenotazione (quello che c'è scritto nel QR dell'evento). */
	public static function ticket_url( int $session_id, int $person_id ): string {
		return add_query_arg( 'apse_ticket', CardToken::ticket_param( $session_id, $person_id, self::card_secret() ), home_url( '/' ) );
	}

	/** Indirizzo di verifica della tessera di una persona (quello che c'è scritto nel QR). */
	public static function card_url( int $person_id ): string {
		return add_query_arg( 'apse_card', CardToken::param( $person_id, self::card_secret() ), home_url( '/' ) );
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

	/**
	 * Chiave segreta in chiaro, decifrata dal database. Stringa vuota se manca o se non è leggibile su questo sito.
	 *
	 * La cifratura dipende dai "salt" di questo sito: copiando il database su un altro sito (ad esempio da produzione a staging)
	 * le chiavi NON sono leggibili lì. È voluto: lo staging non può usare per sbaglio le chiavi reali dei pagamenti.
	 */
	public static function secret( string $key ): string {
		$plain = Secrets::decrypt( (string) self::get( $key ), wp_salt( 'auth' ) );
		return null === $plain ? '' : $plain;
	}

	/** True se c'è una chiave salvata (anche se non leggibile su questo sito). */
	public static function has_secret( string $key ): bool {
		return '' !== (string) self::get( $key );
	}

	/** True se la chiave è salvata ma non si riesce a leggerla (database copiato da un altro sito, salt cambiati). */
	public static function secret_unreadable( string $key ): bool {
		return self::has_secret( $key ) && '' === self::secret( $key );
	}

	/** Configurazione dei pagamenti con i segreti in chiaro (solo per uso interno: mai da stampare). */
	public static function payment_config(): array {
		$c = array( 'payment_provider' => (string) self::get( 'payment_provider' ) );
		foreach ( array( 'stripe_mode', 'stripe_publishable_key', 'paypal_mode', 'paypal_client_id' ) as $k ) {
			$c[ $k ] = (string) self::get( $k );
		}
		foreach ( self::SECRET_KEYS as $k ) {
			$c[ $k ] = self::secret( $k );
		}
		return $c;
	}

	public static function payment_hint(): string {
		return (string) self::get( 'payment_hint' );
	}

	public static function start_month(): int {
		return (int) self::get( 'social_year_start_month' );
	}

	public static function social_year( ?string $date = null ): SocialYear {
		return SocialYear::for_date( $date ?? Db::today(), self::start_month() );
	}
}
