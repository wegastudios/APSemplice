<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/** Tutte le impostazioni si cambiano dal pannello (menu APSemplice → Impostazioni): nessun file da modificare. */
final class Settings {

	const OPTION = 'apse_settings';

	/** Chiavi segrete: nel database restano cifrate e non vengono mai mostrate. */
	const SECRET_KEYS = array( 'stripe_secret_key', 'stripe_webhook_secret', 'paypal_client_secret', 'wallet_apple_key_pem', 'wallet_google_key_pem' );

	const DEFAULT_PAYMENT_HINT = 'Il pagamento si effettua in sede presso la segreteria.';

	public static function defaults(): array {
		return array(
			'association_name'        => '',
			'tax_code'                => '',
			'social_year_start_month' => 9,     // l'anno sociale inizia a settembre
			'membership_fee_cents'    => 1000,  // quota associativa proposta negli incassi
			'founder_years'           => 99,    // durata della tessera del socio fondatore
			'guest_max_events'        => 2,     // quante volte un non socio può partecipare (eventi e corsi) prima di doversi iscrivere; 0 = nessun limite
			'board_councillors'       => 7,     // posti da consigliere nel consiglio direttivo (più 1 presidente e 1 vicepresidente)
			'card_enabled'            => 1,     // tessera digitale nell'area soci
			'reports_enabled'         => 1,     // report e rendiconto
			'pwa_enabled'             => 0,     // app installabile (PWA): spenta di default
			'push_enabled'            => 0,     // notifiche push: spente di default
			'pwa_name'                => '',    // nome dell'app (vuoto = denominazione)
			'pwa_short_name'          => '',    // nome breve sotto l'icona
			'pwa_icon_id'             => 0,     // icona dell'app (immagine PNG della libreria media)
			'woo_default_product'     => 0,     // prodotto WooCommerce per le voci senza un prodotto proprio
			'language'                => 'it',  // lingua dei testi (pacchetti di traduzione)
			'fivepm_enabled'          => 0,     // 5x1000: spento di default
			'fivepm_text'             => '',    // messaggio personalizzato (vuoto = quello standard)
			'insurance_volunteers'    => 0,     // registro delle assicurazioni dei volontari: spento di default
			'insurance_association'   => 0,     // polizze dell'associazione (responsabilità civile, infortuni): spente di default
			'family_discount_pct'     => 0,    // sconto sulla quota dei familiari del capofamiglia (%)
			'entity_type'             => 'associazione', // tipo di ente: i testi si adattano (articoli compresi)
			'entity_types_custom'     => '',    // tipi aggiunti a mano, uno per riga: nome;m|f
			'member_term'             => 'socio', // come si chiamano le persone che partecipano (singolare)
			'member_terms_custom'     => '',    // termini aggiunti a mano, uno per riga: singolare;plurale;m|f
			'reminders_enabled'       => 0,     // promemoria automatici per email: spenti di default
			'reminders_membership'    => 1,     // ... tessera in scadenza o scaduta
			'reminders_membership_days' => 30,  // ... quanti giorni prima della scadenza
			'reminders_dues'          => 1,     // ... mensilità dei corsi non pagate
			'reminders_events'        => 1,     // ... evento il giorno dopo
			'rules_enabled'           => 0,     // regolamento: se attivo, i soci devono accettarlo
			'rules_title'             => 'Regolamento',
			'rules_text'              => '',    // testo del regolamento (facoltativo se c'è l'indirizzo di una pagina)
			'rules_url'               => '',    // pagina con il regolamento (facoltativa se c'è il testo)
			'rules_version'           => '1',   // cambiandola, tutti devono accettare di nuovo
			'rules_block_booking'     => 1,     // senza accettazione non si prenota
			'privacy_url'             => '',    // pagina con l'informativa privacy: se c'è, chi attiva l'accesso deve accettarla
			'privacy_retention_years' => 5,     // dopo quanti anni di inattività si propone l'anonimizzazione
			'receipt_footer'          => '',    // riga in fondo alle ricevute (es. riferimento normativo): la decide l'associazione
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
			'ticket_qr_enabled'       => 0,     // biglietti QR delle prenotazioni (poi si scelgono per singolo evento)
			'wallet_enabled'          => 0,     // pulsanti Apple/Google Wallet
			'ical_enabled'            => 0,     // indirizzo del calendario per Google Calendar e simili
			'wallet_apple_pass_type'  => '',    // Apple Wallet: identificativo del tipo di pass (dal certificato)
			'wallet_apple_team'       => '',
			'wallet_apple_cert_pem'   => '',    // certificato (pubblico)
			'wallet_apple_wwdr_pem'   => '',    // certificato intermedio di Apple (pubblico)
			'wallet_apple_key_pem'    => '',    // cifrata
			'wallet_google_issuer'    => '',    // Google Wallet: ID dell'emittente
			'wallet_google_email'     => '',    // account di servizio
			'wallet_google_key_pem'   => '',    // cifrata
			'wpai_default_type'       => 'ordinary', // import da WP All Import: tipo socio se manca la colonna
			'wpai_default_account_id' => 0,         // ... e conto della prima nota se manca
			'wpai_keep_balances'      => 1,         // ... non cambiare i saldi attuali dei conti
			'wpai_mark_members'       => 0,         // ... segna i soci come iscritti all'anno sociale corrente
			'modules'                 => array(),   // parti del gestionale usate (vedi Modules): vuoto = tutte, come prima della configurazione guidata
			'join_mode'               => 'request', // chi non è in elenco: 'request' = può chiedere l'accesso alla segreteria; 'invite' = solo su presentazione
			'limits'                  => array(),   // limiti e soglie personalizzati (vedi Limits): vuoto = valori predefiniti
			'pay_label_stripe'        => '',        // diciture dei pulsanti di pagamento (vuoto = predefinita, vedi PaymentConfig)
			'pay_note_stripe'         => '',
			'pay_label_paypal'        => '',
			'pay_note_paypal'         => '',
			'pay_label_woocommerce'   => '',
			'pay_note_woocommerce'    => '',
			'bank_enabled'            => 0,         // coordinate bancarie (IBAN) mostrate ai soci e inviate per email: spente di default
			'bank_title'              => '',        // titolo del riquadro (vuoto = «Pagamento con bonifico»)
			'bank_note'               => '',        // istruzioni sotto le coordinate (vuoto = testo predefinito)
			'bank_in_reminders'       => 1,         // ... aggiunte anche ai promemoria di pagamento
			'bank_accounts'           => array(),   // conti: label, holder, iban, bic, bank, note (al massimo 5)
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
		$clean['guest_max_events']         = max( 0, min( 20, (int) $clean['guest_max_events'] ) );
		$clean['board_councillors']       = max( 0, min( 30, (int) $clean['board_councillors'] ) );
		$clean['card_enabled']            = empty( $clean['card_enabled'] ) ? 0 : 1;
		$clean['reports_enabled']         = empty( $clean['reports_enabled'] ) ? 0 : 1;
		$clean['pwa_enabled']             = empty( $clean['pwa_enabled'] ) ? 0 : 1;
		$clean['push_enabled']            = empty( $clean['push_enabled'] ) ? 0 : 1;
		$clean['pwa_name']                = mb_substr( trim( (string) $clean['pwa_name'] ), 0, 45 );
		$clean['pwa_short_name']          = mb_substr( trim( (string) $clean['pwa_short_name'] ), 0, 12 );
		$clean['pwa_icon_id']             = max( 0, (int) $clean['pwa_icon_id'] );
		$clean['woo_default_product']     = max( 0, (int) $clean['woo_default_product'] );
		$clean['language']                = preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?$/', (string) $clean['language'] ) ? (string) $clean['language'] : 'it';
		$clean['fivepm_enabled']          = empty( $clean['fivepm_enabled'] ) ? 0 : 1;
		$clean['fivepm_text']             = mb_substr( trim( (string) $clean['fivepm_text'] ), 0, 1000 );
		$clean['insurance_volunteers']     = empty( $clean['insurance_volunteers'] ) ? 0 : 1;
		$clean['insurance_association']    = empty( $clean['insurance_association'] ) ? 0 : 1;
		$clean['family_discount_pct']      = max( 0, min( 100, (int) $clean['family_discount_pct'] ) );
		$clean['member_area_page_id']     = max( 0, (int) $clean['member_area_page_id'] );
		$clean['entity_type']         = mb_substr( trim( (string) $clean['entity_type'] ), 0, 60 );
		$clean['member_term']         = mb_substr( trim( (string) $clean['member_term'] ), 0, 60 );
		$clean['entity_types_custom'] = mb_substr( trim( (string) $clean['entity_types_custom'] ), 0, 1000 );
		$clean['member_terms_custom'] = mb_substr( trim( (string) $clean['member_terms_custom'] ), 0, 1000 );
		\ApSemplice\Terms::flush();
		foreach ( array( 'reminders_enabled', 'reminders_membership', 'reminders_dues', 'reminders_events' ) as $k ) {
			$clean[ $k ] = empty( $clean[ $k ] ) ? 0 : 1;
		}
		$clean['reminders_membership_days'] = max( 1, min( 120, (int) $clean['reminders_membership_days'] ) );
		$clean['privacy_url']             = esc_url_raw( trim( (string) $clean['privacy_url'] ) );
		$clean['rules_enabled']           = empty( $clean['rules_enabled'] ) ? 0 : 1;
		$clean['rules_block_booking']     = empty( $clean['rules_block_booking'] ) ? 0 : 1;
		$clean['rules_title']             = mb_substr( trim( (string) $clean['rules_title'] ), 0, 80 );
		$clean['rules_text']              = mb_substr( trim( str_replace( "\r\n", "\n", (string) $clean['rules_text'] ) ), 0, 30000 );
		$clean['rules_url']               = esc_url_raw( trim( (string) $clean['rules_url'] ) );
		$clean['rules_version']           = mb_substr( trim( (string) $clean['rules_version'] ), 0, 20 );
		$clean['privacy_retention_years'] = max( 1, min( 30, (int) $clean['privacy_retention_years'] ) );
		$clean['receipt_footer']          = mb_substr( trim( (string) $clean['receipt_footer'] ), 0, 300 );
		$clean['license_key']             = mb_substr( trim( (string) $clean['license_key'] ), 0, 120 );
		$clean['cancel_policy_default']   = CancelPolicy::is_valid( (string) $clean['cancel_policy_default'] ) ? (string) $clean['cancel_policy_default'] : CancelPolicy::H48;
		$clean['accent_color']            = Color::normalize( (string) $clean['accent_color'] );
		$clean['payment_hint']            = '' === trim( (string) $clean['payment_hint'] ) ? self::DEFAULT_PAYMENT_HINT : mb_substr( trim( (string) $clean['payment_hint'] ), 0, 300 );
		$clean['gate_message']            = mb_substr( trim( (string) $clean['gate_message'] ), 0, 200 );
		$clean['payment_provider']        = PaymentConfig::is_valid( (string) $clean['payment_provider'] ) ? (string) $clean['payment_provider'] : PaymentConfig::NONE;
		$clean['stripe_mode']             = 'live' === $clean['stripe_mode'] ? 'live' : 'test';
		$clean['paypal_mode']             = 'live' === $clean['paypal_mode'] ? 'live' : 'sandbox';
		$clean['wpai_default_type']       = MemberType::is_member( (string) $clean['wpai_default_type'] ) ? (string) $clean['wpai_default_type'] : MemberType::ORDINARY;
		$clean['wpai_default_account_id'] = max( 0, (int) $clean['wpai_default_account_id'] );
		$clean['wpai_keep_balances']      = empty( $clean['wpai_keep_balances'] ) ? 0 : 1;
		$clean['card_qr_enabled']         = empty( $clean['card_qr_enabled'] ) ? 0 : 1;
		$clean['ticket_qr_enabled']       = empty( $clean['ticket_qr_enabled'] ) ? 0 : 1;
		$clean['wallet_enabled']          = empty( $clean['wallet_enabled'] ) ? 0 : 1;
		$clean['ical_enabled']            = empty( $clean['ical_enabled'] ) ? 0 : 1;
		foreach ( array( 'wallet_apple_cert_pem', 'wallet_apple_wwdr_pem' ) as $k ) {
			$clean[ $k ] = mb_substr( trim( (string) $clean[ $k ] ), 0, 12000 );
		}
		foreach ( array( 'wallet_apple_pass_type', 'wallet_apple_team', 'wallet_google_issuer', 'wallet_google_email' ) as $k ) {
			$clean[ $k ] = mb_substr( trim( (string) $clean[ $k ] ), 0, 200 );
		}
		$clean['wpai_mark_members']       = empty( $clean['wpai_mark_members'] ) ? 0 : 1;
		$clean['modules']                 = Modules::sanitize( is_array( $clean['modules'] ) ? $clean['modules'] : array() );
		$clean['join_mode']               = 'invite' === $clean['join_mode'] ? 'invite' : 'request';
		$clean['limits']                  = Limits::sanitize( is_array( $clean['limits'] ) ? $clean['limits'] : array() );
		foreach ( array( 'stripe', 'paypal', 'woocommerce' ) as $g ) {
			$clean[ 'pay_label_' . $g ] = mb_substr( trim( (string) $clean[ 'pay_label_' . $g ] ), 0, 60 );
			$clean[ 'pay_note_' . $g ]  = mb_substr( trim( (string) $clean[ 'pay_note_' . $g ] ), 0, 300 );
		}
		$clean['bank_enabled']            = empty( $clean['bank_enabled'] ) ? 0 : 1;
		$clean['bank_in_reminders']       = empty( $clean['bank_in_reminders'] ) ? 0 : 1;
		$clean['bank_title']              = mb_substr( trim( (string) $clean['bank_title'] ), 0, 80 );
		$clean['bank_note']               = mb_substr( trim( (string) $clean['bank_note'] ), 0, 400 );
		$clean['bank_accounts']           = Bank::sanitize_accounts( is_array( $clean['bank_accounts'] ) ? $clean['bank_accounts'] : array() );
		foreach ( array( 'stripe_publishable_key', 'paypal_client_id' ) as $k ) {
			$clean[ $k ] = mb_substr( trim( (string) $clean[ $k ] ), 0, 200 );
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

	public static function tickets_enabled(): bool {
		return ! empty( self::get( 'ticket_qr_enabled' ) );
	}

	public static function wallet_enabled(): bool {
		return ! empty( self::get( 'wallet_enabled' ) );
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
		$c = array( 'payment_provider' => (string) self::get( 'payment_provider' ), 'site_https' => 0 === strpos( home_url(), 'https://' ) ? 1 : 0 );
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

	/** Registro delle assicurazioni dei volontari attivo? */
	public static function insurance_volunteers(): bool {
		return (bool) self::get( 'insurance_volunteers' );
	}

	/** Polizze dell'associazione attive? */
	public static function insurance_association(): bool {
		return (bool) self::get( 'insurance_association' );
	}

	/** Sconto (%) sulla quota dei familiari di un capofamiglia. */
	public static function family_discount(): int {
		return max( 0, min( 100, (int) self::get( 'family_discount_pct' ) ) );
	}

	public static function councillors(): int {
		return max( 0, (int) self::get( 'board_councillors' ) );
	}

	/** Anno della tessera associativa: l'anno solare, la scadenza è sempre il 31 dicembre (i soci fondatori sono a parte). */
	public static function membership_year( ?string $date = null ): SocialYear {
		return SocialYear::for_date( $date ?? Db::today(), 1 );
	}
}
