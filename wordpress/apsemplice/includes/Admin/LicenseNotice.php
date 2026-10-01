<?php
namespace ApSemplice\Admin;

use ApSemplice\License;
use ApSemplice\LicensePolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Popup che copre le pagine del plugin quando la licenza non è in regola.
 * I dati restano leggibili sotto il popup. Nella prima settimana si può chiudere; dopo non più.
 * La pagina Impostazioni è esclusa, così si può correggere la chiave di licenza.
 */
final class LicenseNotice {

	public static function register(): void {
		add_action( 'admin_footer', array( __CLASS__, 'print_notice' ) );
	}

	public static function print_notice(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 !== strpos( $page, 'apse' ) || 'apse-settings' === $page ) {
			return;
		}
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Markup del popup, stringa vuota se la licenza è in regola (o in standby). */
	public static function html(): string {
		$p = License::policy();
		if ( LicensePolicy::POPUP_NONE === $p['popup'] ) {
			return '';
		}
		$url   = License::payment_url();
		$html  = '<div id="apse-license-overlay" class="apse-overlay" role="dialog" aria-modal="true" aria-labelledby="apse-overlay-title"><div class="apse-overlay-box">';
		$html .= '<h2 id="apse-overlay-title">Licenza non in regola</h2><p>' . esc_html( LicensePolicy::message( $p['status'] ) ) . '</p>';
		$html .= '<p>Finché non viene regolarizzata, <strong>l\'esportazione dei dati e l\'accesso di soci e volontari sono sospesi</strong>. I tuoi dati sono al sicuro e restano intatti.</p>';
		$html .= $url
			? '<p><a class="button button-primary button-hero" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Regolarizza il pagamento</a></p>'
			: '<p>Contatta il fornitore del servizio per regolarizzare il pagamento.</p>';
		if ( LicensePolicy::POPUP_CLOSABLE === $p['popup'] ) {
			$html .= '<p class="description">Puoi chiudere questo avviso ancora per ' . (int) $p['days_left'] . ' ' . ( 1 === (int) $p['days_left'] ? 'giorno' : 'giorni' )
				. '; poi resterà sempre visibile.</p><p><button type="button" class="button" id="apse-overlay-close">Chiudi per ora</button></p>';
		} else {
			$html .= '<p class="description">Il periodo in cui l\'avviso si poteva chiudere è terminato.</p>';
		}
		return $html . '</div></div>';
	}
}
