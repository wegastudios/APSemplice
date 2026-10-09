<?php
namespace ApSemplice\Admin;

use ApSemplice\License;
use ApSemplice\LicensePolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Avviso in cima alle pagine del plugin quando la licenza di APSemplice Pro non è in regola. Non copre niente e non blocca niente di ciò che
 * c'è nell'edizione di base: ricorda solo che le funzioni avanzate sono sospese e come regolarizzare. I dati restano sempre accessibili.
 */
final class LicenseNotice {

	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'print_notice' ) );
	}

	public static function print_notice(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 !== strpos( $page, 'apse' ) ) {
			return;
		}
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Markup dell'avviso, stringa vuota se la licenza è in regola (o in standby). */
	public static function html(): string {
		if ( ! License::degraded() ) {
			return '';
		}
		$p   = License::policy();
		$url = License::payment_url();
		$html  = '<div class="notice notice-warning apse-license-notice"><p><strong>Licenza di APSemplice Pro non in regola.</strong> ' . esc_html( LicensePolicy::message( $p['status'] ) ) . '</p>';
		$html .= '<p>Le funzioni avanzate (pagamenti online, conti e fondi, contabilità, comunicazioni, app e quote diverse per i soci) sono sospese; il resto funziona e i tuoi dati sono al sicuro. ';
		$html .= $url
			? '<a class="button button-primary" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Regolarizza la licenza</a>'
			: 'Contatta il fornitore del servizio per regolarizzare la licenza.';
		return $html . '</p></div>';
	}
}
