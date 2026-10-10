<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Configurazione guidata: in un'unica pagina raccoglie le impostazioni principali (ente, quote, funzioni, pagamenti, pagine del sito)
 * e le applica insieme. Si propone al primo avvio e si riapre quando serve da Impostazioni.
 */
final class Wizard {

	const OPTION    = 'asem_wizard_status';
	const TRANSIENT = 'asem_wizard_redirect';

	/** Funzioni che non stanno nell'elenco delle funzioni facoltative: app e notifiche hanno la loro scheda, il 5x1000 il suo passo (con la contabilità). */
	const OWN_STEP = array( 'pwa_enabled', 'push_enabled', 'fivepm_enabled' );

	const DONE    = 'done';
	const SKIPPED = 'skipped';

	/** Scelte di pagamento: valore => [etichetta, descrizione]. */
	public static function payment_choices(): array {
		return array(
			'none'                    => array( 'Nessun pagamento online', 'I pagamenti si registrano a mano (contanti, POS, bonifico).' ),
			PaymentConfig::STRIPE     => array( 'Carta con Stripe', 'Il socio paga con carta direttamente dal sito.' ),
			PaymentConfig::PAYPAL     => array( 'PayPal', 'Il socio paga con PayPal (anche a rate, se il tuo account lo prevede).' ),
			PaymentConfig::BOTH       => array( 'Stripe e PayPal insieme', 'Il socio sceglie come pagare; le diciture dei pulsanti si personalizzano in Pagamenti online.' ),
			PaymentConfig::WOOCOMMERCE => array( 'Checkout di WooCommerce', 'I pagamenti passano dal carrello e dal checkout del tuo negozio.' ),
		);
	}

	/** Guide dei fornitori, da aprire in una nuova finestra. @return array<string,array<int,array{0:string,1:string}>> */
	public static function tutorials(): array {
		return array(
			PaymentConfig::STRIPE      => array(
				array( 'Creare un account Stripe', 'https://dashboard.stripe.com/register' ),
				array( 'Dove trovare le chiavi API', 'https://docs.stripe.com/keys' ),
				array( 'Configurare un webhook', 'https://docs.stripe.com/webhooks' ),
			),
			PaymentConfig::PAYPAL      => array(
				array( 'Creare un\'app PayPal e ottenere le credenziali', 'https://developer.paypal.com/api/rest/' ),
				array( 'Account di prova (sandbox)', 'https://developer.paypal.com/tools/sandbox/' ),
			),
			PaymentConfig::WOOCOMMERCE => array(
				array( 'Primi passi con WooCommerce', 'https://woocommerce.com/document/woocommerce-getting-started/' ),
				array( 'Metodi di pagamento di WooCommerce', 'https://woocommerce.com/document/payments/' ),
			),
		);
	}

	public static function status(): string {
		$s = (string) get_option( self::OPTION, '' );
		return in_array( $s, array( self::DONE, self::SKIPPED ), true ) ? $s : '';
	}

	/** Da proporre in Bacheca finché non è stata completata né rimandata. */
	public static function pending(): bool {
		return '' === self::status();
	}

	public static function mark( string $status ): void {
		update_option( self::OPTION, in_array( $status, array( self::DONE, self::SKIPPED ), true ) ? $status : self::DONE, false );
		delete_transient( self::TRANSIENT );
	}

	/** Alla prima attivazione la configurazione guidata si apre da sola, una volta sola. */
	public static function schedule_first_run(): void {
		if ( self::pending() && false === get_transient( self::TRANSIENT ) ) {
			set_transient( self::TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );
		}
	}

	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
	}

	public static function maybe_redirect(): void {
		if ( ! get_transient( self::TRANSIENT ) || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || isset( $_GET['activate-multi'] ) || is_network_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		delete_transient( self::TRANSIENT );
		if ( self::pending() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=asem-wizard' ) );
			exit;
		}
	}

	/**
	 * Applica le scelte del modulo. Ogni parte è facoltativa: salva solo ciò che è presente.
	 *
	 * @param array $p dati inviati dal modulo
	 * @return string[] messaggi su ciò che è stato fatto
	 * @throws \InvalidArgumentException
	 */
	public static function apply( array $p ): array {
		$done = array();
		$vals = array();

		// 1. Ente
		$vals = EntityData::values( $p, false ); // nome, tipo, codice fiscale, sede, partita IVA: stesse regole della scheda «Dati e fiscalità»

		if ( array_key_exists( 'guests_enabled', $p ) ) {
			$vals['guests_enabled'] = ! empty( $p['guests_enabled'] ) ? 1 : 0;
		}
		if ( isset( $p['join_mode'] ) ) {
			$vals['join_mode'] = 'invite' === (string) $p['join_mode'] ? 'invite' : 'request';
		}

		// Parti del gestionale usate
		if ( ! empty( $p['mod_present'] ) ) {
			$mods = array();
			foreach ( array_keys( Modules::defs() ) as $k ) {
				$mods[ $k ] = ! empty( $p['mod'][ $k ] ) ? 1 : 0;
			}
			$mods['import'] = 1; // le importazioni sono sempre disponibili (dagli Strumenti): non si chiedono
			$vals['modules']         = $mods;
			$vals['reports_enabled'] = ! empty( $mods['reports'] ) ? 1 : 0;
		}

		// Adempimenti (solo con la contabilità): 5x1000
		if ( ! empty( $p['adempimenti_present'] ) ) {
			$vals['fivepm_enabled'] = ! empty( $p['fivepm_enabled'] ) ? 1 : 0;
		}

		// Informativa privacy: si sceglie tra le pagine che esistono già su WordPress
		if ( array_key_exists( 'privacy_page_id', $p ) ) {
			$pid = (int) $p['privacy_page_id'];
			if ( $pid > 0 ) {
				if ( 'page' !== get_post_type( $pid ) || 'publish' !== get_post_status( $pid ) ) {
					throw new \InvalidArgumentException( 'La pagina scelta per l\'informativa privacy non esiste o non è pubblicata.' );
				}
				$vals['privacy_url'] = (string) get_permalink( $pid );
			} elseif ( '' !== (string) Settings::get( 'privacy_url' ) && url_to_postid( (string) Settings::get( 'privacy_url' ) ) > 0 ) {
				$vals['privacy_url'] = ''; // era una pagina del sito e ora non se ne vuole nessuna (un indirizzo esterno non si tocca)
			}
		}

		// Privacy e ricevute
		if ( isset( $p['privacy_url'] ) ) {
			$url = trim( (string) $p['privacy_url'] );
			if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
				throw new \InvalidArgumentException( 'L\'indirizzo dell\'informativa privacy deve iniziare con http:// o https://.' );
			}
			$vals['privacy_url'] = $url;
		}
		if ( isset( $p['privacy_retention_years'] ) && '' !== trim( (string) $p['privacy_retention_years'] ) ) {
			$vals['privacy_retention_years'] = max( 1, min( 30, (int) $p['privacy_retention_years'] ) );
		}
		if ( isset( $p['receipt_footer'] ) ) {
			$vals['receipt_footer'] = (string) $p['receipt_footer'];
		}

		// 2. Anno sociale e quote
		if ( isset( $p['social_year_start_month'] ) ) {
			$vals['social_year_start_month'] = max( 1, min( 12, (int) $p['social_year_start_month'] ) );
		}
		if ( isset( $p['membership_fee'] ) && '' !== trim( (string) $p['membership_fee'] ) ) {
			$fee = Money::parse( (string) $p['membership_fee'] );
			if ( null === $fee || $fee < 0 ) {
				throw new \InvalidArgumentException( 'La quota associativa non è un importo valido.' );
			}
			$vals['membership_fee_cents'] = $fee;
		}
		if ( isset( $p['family_discount_pct'] ) && '' !== trim( (string) $p['family_discount_pct'] ) ) {
			$vals['family_discount_pct'] = max( 0, min( 100, (int) $p['family_discount_pct'] ) );
		}

		// 3. Funzioni facoltative (le app e le notifiche hanno la loro scheda)
		if ( ! empty( $p['features_present'] ) ) {
			foreach ( array_keys( \AssociazioneSemplice\Admin\SettingsPage::FEATURES ) as $k ) {
				if ( in_array( $k, self::OWN_STEP, true ) ) {
					continue;
				}
				$vals[ $k ] = ! empty( $p[ $k ] ) ? 1 : 0;
			}
		}

		// 4. Pagamenti
		$woo_products = array();
		if ( isset( $p['payment_choice'] ) ) {
			$choice  = (string) $p['payment_choice'];
			$choices = self::payment_choices();
			if ( ! isset( $choices[ $choice ] ) ) {
				throw new \InvalidArgumentException( 'Scelta dei pagamenti non valida.' );
			}
			if ( PaymentConfig::WOOCOMMERCE === $choice ) {
				if ( ! WooBridge::active() ) {
					throw new \InvalidArgumentException( 'WooCommerce non è attivo su questo sito: installalo e attivalo, poi riapri la configurazione guidata.' );
				}
				if ( ! WooBridge::currency_is_euro() ) {
					throw new \InvalidArgumentException( 'Il negozio WooCommerce deve usare l\'euro come valuta: le quote sono in euro.' );
				}
			}
			$vals['payment_provider'] = 'none' === $choice ? PaymentConfig::NONE : $choice;
			$vals['bank_enabled']     = ! empty( $p['bank_enabled'] ) ? 1 : 0;
			$woo_products             = PaymentConfig::WOOCOMMERCE === $choice ? $p : array();
		}

		// Altri tipi di socio: righe «Nome; quota». I livelli esistenti restano come sono.
		$new_levels = Edition::has( 'levels' ) ? self::parse_levels( (string) ( $p['extra_levels'] ?? '' ) ) : array();
		if ( $new_levels ) {
			$rows  = array();
			$known = array();
			foreach ( Levels::all() as $lv ) {
				$rows[]                                  = array( 'id' => (int) $lv['id'], 'name' => $lv['name'], 'base_type' => $lv['base_type'], 'fee' => null === $lv['fee_cents'] ? '' : Money::plain( (int) $lv['fee_cents'] ), 'active' => (int) $lv['active'] );
				$known[ mb_strtolower( $lv['name'], 'UTF-8' ) ] = true;
			}
			$added = 0;
			foreach ( $new_levels as $nl ) {
				if ( isset( $known[ mb_strtolower( $nl[0], 'UTF-8' ) ] ) ) {
					continue;
				}
				$rows[] = array( 'id' => 0, 'name' => $nl[0], 'base_type' => MemberType::ORDINARY, 'fee' => $nl[1], 'active' => 1 );
				$added++;
			}
			if ( $added ) {
				Levels::save( $rows );
				$done[] = $added . ( 1 === $added ? ' tipo di socio aggiunto.' : ' tipi di socio aggiunti.' );
			}
		}

		if ( $vals ) {
			Settings::update( $vals );
			$done[] = 'Impostazioni salvate.';
		}

		// 4b. Prodotti di WooCommerce da collegare o creare
		if ( $woo_products ) {
			$done = array_merge( $done, self::link_products( $woo_products ) );
		}

		// 5. Pagine del sito
		if ( ! empty( $p['pages_present'] ) ) {
			$keys = array_values( array_intersect( array_keys( Pages::defs() ), array_map( 'strval', (array) ( $p['pages'] ?? array() ) ) ) );
			$made = Pages::create( $keys );
			$done[] = $made ? 'Pagine create: ' . implode( ', ', $made ) . '.' : 'Le pagine scelte esistevano già.';
		}

		self::mark( self::DONE );
		Audit::log( 'wizard.completed', 'settings', 0, array( 'steps' => count( $done ) ) );
		return $done;
	}

	/** Righe «Nome; quota» (la quota è facoltativa) => [[nome, quota], …]. */
	public static function parse_levels( string $text ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: array() as $line ) {
			$parts = array_map( 'trim', explode( ';', $line, 2 ) );
			$name  = mb_substr( sanitize_text_field( $parts[0] ), 0, 80 );
			if ( '' === $name ) {
				continue;
			}
			$fee = $parts[1] ?? '';
			if ( '' !== $fee && null === Money::parse( $fee ) ) {
				throw new \InvalidArgumentException( 'La quota di «' . $name . '» non è un importo valido.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			$out[] = array( $name, $fee );
		}
		return array_slice( $out, 0, 20 );
	}

	/** Per ogni livello e per la voce generica: lascia com'è, crea un prodotto nuovo o collega uno esistente. @return string[] */
	private static function link_products( array $p ): array {
		$done = array();
		$fee  = (int) Settings::get( 'membership_fee_cents' );
		foreach ( Levels::all( true ) as $lv ) {
			$choice = (string) ( $p['product_level'][ (int) $lv['id'] ] ?? '' );
			if ( '' === $choice ) {
				continue;
			}
			$pid = 'new' === $choice ? WooBridge::create_product( 'Quota associativa: ' . $lv['name'], null === $lv['fee_cents'] ? $fee : (int) $lv['fee_cents'] ) : (int) $choice;
			WooLinks::set( WooLinks::LEVEL, (int) $lv['id'], $pid );
			$done[] = 'Quota «' . $lv['name'] . '» collegata a un prodotto' . ( 'new' === $choice ? ' (creato ora)' : '' ) . '.';
		}
		$gen = (string) ( $p['product_default'] ?? '' );
		if ( '' !== $gen ) {
			$pid = 'new' === $gen ? WooBridge::create_product( 'Contributo ' . ( '' !== (string) Settings::get( 'association_name' ) ? (string) Settings::get( 'association_name' ) : 'AssociazioneSemplice' ), 0 ) : (int) $gen;
			WooBridge::assert_product( $pid );
			Settings::update( array( 'woo_default_product' => $pid ) );
			$done[] = 'Prodotto generico per le altre voci (corsi ed eventi) impostato' . ( 'new' === $gen ? ' (creato ora)' : '' ) . '.';
		}
		return $done;
	}
}
