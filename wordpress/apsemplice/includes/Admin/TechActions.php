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

	public static function save_acct( array $p ): array {
		Settings::update( array( 'reports_enabled' => ! empty( $p['reports_enabled'] ) ? 1 : 0 ) );
		return array( Ui::url( 'apse-acct' ), 'Opzioni contabili salvate.' );
	}
}
