<?php
namespace ApSemplice\Admin;

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
		if ( ! empty( $_FILES['pwa_icon']['tmp_name'] ) && UPLOAD_ERR_OK === (int) $_FILES['pwa_icon']['error'] ) { // phpcs:ignore WordPress.Security.NonceVerification
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
			throw new \InvalidArgumentException( 'Caricamento dell\'icona non riuscito: ' . $up['error'] );
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

	public static function save_acct( array $p ): array {
		Settings::update( array( 'reports_enabled' => ! empty( $p['reports_enabled'] ) ? 1 : 0 ) );
		return array( Ui::url( 'apse-acct' ), 'Opzioni contabili salvate.' );
	}
}
