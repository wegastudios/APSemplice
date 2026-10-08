<?php
namespace ApSemplice\Admin;

use ApSemplice\Audit;
use ApSemplice\PaymentConfig;
use ApSemplice\Plugin;
use ApSemplice\Settings;
use ApSemplice\WooBridge;
use ApSemplice\WooLinks;

defined( 'ABSPATH' ) || exit;

/** Azioni delle schede Tecniche e Contabilità delle impostazioni: solo amministratori. */
final class TechActions {

	const ACTIONS = array(
		'apse_save_woo'   => 'save_woo',
		'apse_save_roles' => 'save_roles',
		'apse_save_acct'  => 'save_acct',
		'apse_save_app'   => 'save_app',
		'apse_push_test'  => 'push_test',
		'apse_push_reset' => 'push_reset',
		'apse_save_bank'  => 'save_bank',
		'apse_save_limits' => 'save_limits',
		'apse_reset_limits' => 'reset_limits',
		'apse_save_entity' => 'save_entity',
		'apse_reset_all'   => 'reset_all',
		'apse_wizard_save' => 'wizard_save',
		'apse_wizard_skip' => 'wizard_skip',
	);

	public static function register(): void {
		foreach ( self::ACTIONS as $action => $method ) {
			add_action(
				'admin_post_' . $action,
				function () use ( $action, $method ) {
					if ( ! current_user_can( Plugin::CAP ) ) {
						wp_die( 'Non autorizzato.', 403 );
					}
					check_admin_referer( $action );
					$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
					$back = ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : Ui::url( 'apse' );
					try {
						$res = self::$method( $post );
						Ui::redirect( $res[0], $res[1] );
					} catch ( \InvalidArgumentException $e ) {
						Ui::redirect( $back, '', $e->getMessage() );
					} catch ( \Throwable $e ) {
						Ui::redirect( $back, '', 'Errore imprevisto: ' . $e->getMessage() );
					}
				}
			);
		}
	}

	public static function save_woo( array $p ): array {
		$on = ! empty( $p['woo_enabled'] );
		if ( $on && ! WooBridge::active() ) {
			throw new \InvalidArgumentException( 'WooCommerce non è attivo su questo sito: installalo e attivalo prima.' );
		}
		$default = (int) ( $p['woo_default_product'] ?? 0 );
		if ( $default > 0 ) {
			WooBridge::assert_product( $default );
		}
		foreach ( (array) ( $p['link_level'] ?? array() ) as $id => $product ) {
			WooLinks::set( WooLinks::LEVEL, (int) $id, (int) $product );
		}
		foreach ( (array) ( $p['link_activity'] ?? array() ) as $id => $product ) {
			WooLinks::set( WooLinks::ACTIVITY, (int) $id, (int) $product );
		}
		$provider = (string) Settings::get( 'payment_provider' );
		if ( $on ) {
			$provider = PaymentConfig::WOOCOMMERCE;
		} elseif ( PaymentConfig::WOOCOMMERCE === $provider ) {
			$provider = PaymentConfig::NONE;
		}
		Settings::update( array( 'payment_provider' => $provider, 'woo_default_product' => $default ) );
		return array( Ui::url( 'apse-tech' ), $on ? 'WooCommerce attivo: i pagamenti passano dal negozio.' : 'Impostazioni di WooCommerce salvate.' );
	}

	public static function save_roles( array $p ): array {
		$chosen = array_map( 'strval', (array) ( $p['roles'] ?? array() ) );
		foreach ( array_keys( TechPage::roles() ) as $key ) {
			TechPage::set_role_access( (string) $key, in_array( (string) $key, $chosen, true ) );
		}
		return array( Ui::url( 'apse-roles' ), 'Accessi salvati.' );
	}

	public static function save_app( array $p ): array {
		$push = ! empty( $p['push_enabled'] );
		$pwa  = ! empty( $p['pwa_enabled'] );
		if ( $push && ! \ApSemplice\WebPush::supported() ) {
			throw new \InvalidArgumentException( 'Questo server non ha le funzioni di cifratura necessarie per le notifiche (estensione openssl di PHP).' );
		}
		$vals = array( 'pwa_enabled' => $pwa ? 1 : 0, 'push_enabled' => ( $push && $pwa ) ? 1 : 0, 'pwa_name' => (string) ( $p['pwa_name'] ?? '' ), 'pwa_short_name' => (string) ( $p['pwa_short_name'] ?? '' ) );
		if ( ! empty( $p['pwa_icon_remove'] ) ) {
			$vals['pwa_icon_id'] = 0;
		}
		if ( ! empty( $_FILES['pwa_icon']['tmp_name'] ) && UPLOAD_ERR_OK === (int) $_FILES['pwa_icon']['error'] ) { // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- valore verificato e ripulito da chi lo usa
			$vals['pwa_icon_id'] = self::upload_icon( $_FILES['pwa_icon'] ); // phpcs:ignore WordPress.Security
		}
		Settings::update( $vals );
		if ( $pwa ) {
			\ApSemplice\Push::vapid(); // crea le chiavi del sito se non ci sono
		}
		return array( Ui::url( 'apse-app' ), $pwa ? 'App e notifiche salvate.' : 'App spenta.' );
	}

	/** Icona dell'app: PNG quadrato di almeno 192 pixel (meglio 512). @return int id dell'immagine nella libreria media */
	private static function upload_icon( array $file ): int {
		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) || (int) $file['size'] > 2 * 1048576 ) {
			throw new \InvalidArgumentException( 'Caricamento dell\'icona non riuscito (PNG, massimo 2 MB).' );
		}
		$info = @getimagesize( (string) $file['tmp_name'] );
		if ( ! $info || IMAGETYPE_PNG !== $info[2] || $info[0] !== $info[1] || $info[0] < 192 ) {
			throw new \InvalidArgumentException( 'L\'icona deve essere un\'immagine PNG quadrata di almeno 192 pixel (meglio 512×512).' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$up = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => array( 'png' => 'image/png' ) ) );
		if ( ! empty( $up['error'] ) ) {
			throw new \InvalidArgumentException( 'Caricamento dell\'icona non riuscito: ' . $up['error'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Icona dell\'app', 'post_status' => 'inherit' ), $up['file'] );
		if ( ! $id || is_wp_error( $id ) ) {
			throw new \InvalidArgumentException( 'Impossibile salvare l\'icona.' );
		}
		wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, $up['file'] ) );
		return (int) $id;
	}

	public static function push_test( array $p ): array {
		if ( ! \ApSemplice\Push::enabled() ) {
			throw new \InvalidArgumentException( 'Le notifiche non sono attive: accendile e salva.' );
		}
		$n = \ApSemplice\Push::notify_users( array( get_current_user_id() ), 'Prova delle notifiche', 'Se leggi questo messaggio le notifiche funzionano.', \ApSemplice\Gatekeeper::area_url() );
		return array( Ui::url( 'apse-app' ), $n > 0 ? 'Notifica di prova inviata a ' . $n . ( 1 === $n ? ' dispositivo.' : ' dispositivi.' ) : 'Nessun dispositivo collegato al tuo utente: apri l\'area soci dal telefono e attiva le notifiche.' );
	}

	public static function push_reset( array $p ): array {
		\ApSemplice\Push::reset_keys();
		return array( Ui::url( 'apse-app' ), 'Chiavi rigenerate: i dispositivi devono riattivare le notifiche.' );
	}

	/** Coordinate bancarie: IBAN controllati (cifra di controllo) e, se cambiano, avviso agli amministratori. */
	public static function save_bank( array $p ): array {
		$rows = array();
		foreach ( (array) ( $p['bank'] ?? array() ) as $r ) {
			if ( is_array( $r ) ) {
				$rows[] = $r;
			}
		}
		$accounts = \ApSemplice\Bank::sanitize_accounts( $rows, true ); // un IBAN sbagliato è un errore, non si scarta in silenzio
		$enabled  = ! empty( $p['bank_enabled'] );
		if ( $enabled && ! $accounts ) {
			throw new \InvalidArgumentException( 'Per attivare il bonifico indica almeno un IBAN.' );
		}
		$old = \ApSemplice\Bank::accounts();
		Settings::update(
			array(
				'bank_enabled'      => $enabled ? 1 : 0,
				'bank_title'        => sanitize_text_field( (string) ( $p['bank_title'] ?? '' ) ),
				'bank_note'         => sanitize_textarea_field( (string) ( $p['bank_note'] ?? '' ) ),
				'bank_in_reminders' => ! empty( $p['bank_in_reminders'] ) ? 1 : 0,
				'bank_accounts'     => $accounts,
			)
		);
		\ApSemplice\Bank::notify_change( $old, $accounts );
		return array( Ui::url( 'apse-payments' ), 'Coordinate bancarie salvate.' . ( \ApSemplice\Bank::signature( $old ) !== \ApSemplice\Bank::signature( $accounts ) ? ' Gli amministratori sono stati avvisati per email del cambio.' : '' ) );
	}

	/** Limiti e soglie: ogni valore resta entro i limiti di sicurezza della sua voce. */
	public static function save_limits( array $p ): array {
		$in = array();
		foreach ( \ApSemplice\Limits::defs() as $key => $d ) {
			if ( 'flag' === $d['unit'] ) {
				$in[ $key ] = ! empty( $p['lim'][ $key ] ) ? 1 : 0; // le caselle non spuntate non arrivano nel modulo
			} elseif ( isset( $p['lim'][ $key ] ) ) {
				$in[ $key ] = $p['lim'][ $key ];
			}
		}
		Settings::update( array( 'limits' => $in ) );
		Audit::log( 'limits.saved', 'settings' );
		return array( Ui::url( 'apse-limits' ), 'Limiti e soglie salvati.' );
	}

	public static function reset_limits( array $p ): array {
		Settings::update( array( 'limits' => array() ) );
		Audit::log( 'limits.reset', 'settings' );
		return array( Ui::url( 'apse-limits' ), 'Ripristinati i valori predefiniti.' );
	}

	/** Azzeramento dei dati: spunta, frase scritta e password dell'amministratore che opera, poi copia di sicurezza e cancellazione. */
	public static function reset_all( array $p ): array {
		$user = wp_get_current_user();
		if ( empty( $p['confirm'] ) ) {
			throw new \InvalidArgumentException( 'Spunta la conferma: i dati verranno cancellati per sempre.' );
		}
		if ( trim( (string) ( $p['phrase'] ?? '' ) ) !== \ApSemplice\Reset::PHRASE ) {
			throw new \InvalidArgumentException( 'La frase scritta non corrisponde: non è stato cancellato nulla.' );
		}
		if ( ! $user || ! $user->exists() || '' === (string) ( $p['password'] ?? '' ) || ! wp_check_password( (string) $p['password'], $user->user_pass, $user->ID ) ) {
			throw new \InvalidArgumentException( 'La password non è corretta: non è stato cancellato nulla.' );
		}
		$mode = 'factory' === (string) ( $p['mode'] ?? '' ) ? \ApSemplice\Reset::FACTORY : \ApSemplice\Reset::DATA;
		$r    = \ApSemplice\Reset::run( $mode, ! empty( $p['users'] ) );
		$msg  = ( \ApSemplice\Reset::FACTORY === $mode ? 'Ripristino di fabbrica completato.' : 'Dati azzerati.' ) . ' Prima di cancellare è stata salvata una copia completa tra le copie di sicurezza (' . $r['copy'] . ').'
			. ( $r['users'] ? ' Eliminati ' . (int) $r['users'] . ' accessi di soci.' : '' );
		return array( \ApSemplice\Reset::FACTORY === $mode ? Ui::url( 'apse-wizard' ) : Ui::url( 'apse' ), $msg );
	}

	/** Dati dell'ente e fiscali. Con la partita IVA attiva il numero deve essere valido. */
	public static function save_entity( array $p ): array {
		$vals = \ApSemplice\EntityData::values( $p, true );
		Settings::update( $vals );
		Audit::log( 'entity.saved', 'settings', 0, array( 'has_vat' => $vals['has_vat'] ) );
		return array( Ui::url( 'apse-entity' ), 'Dati dell\'ente salvati.' );
	}

	/** Configurazione guidata: applica le scelte e porta dove serve ancora qualcosa (chiavi dei pagamenti, IBAN). */
	public static function wizard_save( array $p ): array {
		$done = \ApSemplice\Wizard::apply( $p );
		$next = Ui::url( 'apse' );
		$more = '';
		$prov = (string) Settings::get( 'payment_provider' );
		if ( in_array( $prov, array( PaymentConfig::STRIPE, PaymentConfig::PAYPAL, PaymentConfig::BOTH ), true ) ) {
			$next = Ui::url( 'apse-payments' );
			$more = ' Ora inserisci le chiavi del fornitore scelto.';
		} elseif ( Settings::get( 'bank_enabled' ) && ! \ApSemplice\Bank::enabled() ) {
			$next = Ui::url( 'apse-payments' );
			$more = ' Ora aggiungi almeno un IBAN per il bonifico.';
		}
		if ( ! empty( $p['go_import'] ) && \ApSemplice\Modules::on( 'import' ) && Ui::url( 'apse' ) === $next ) { // l'elenco si carica subito dopo (se non serve prima inserire chiavi o IBAN)
			$next  = Ui::url( 'apse-import' );
			$more .= ' Ora carica l\'elenco dei soci e degli ospiti.';
		} elseif ( ! empty( $p['go_import'] ) && \ApSemplice\Modules::on( 'import' ) ) {
			$more .= ' Poi importa l\'elenco da Strumenti → Importa da Excel/CSV.';
		}
		return array( $next, 'Configurazione completata. ' . implode( ' ', $done ) . $more );
	}

	public static function wizard_skip( array $p ): array {
		\ApSemplice\Wizard::mark( \ApSemplice\Wizard::SKIPPED );
		return array( Ui::url( 'apse' ), 'Configurazione guidata rimandata: la riapri quando vuoi da Strumenti.' );
	}

	public static function save_acct( array $p ): array {
		Settings::update( array( 'reports_enabled' => ! empty( $p['reports_enabled'] ) ? 1 : 0 ) );
		return array( Ui::url( 'apse-acct' ), 'Opzioni contabili salvate.' );
	}
}
