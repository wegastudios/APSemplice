<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\License;
use AssociazioneSemplice\LicensePolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Avviso in cima alle pagine del plugin quando la licenza di Associazione Semplice Pro non è in regola. Non copre niente e non blocca niente di ciò che
 * c'è nell'edizione di base: ricorda solo che le funzioni avanzate sono sospese e come regolarizzare. I dati restano sempre accessibili.
 */
final class LicenseNotice {

	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'print_notice' ) );
	}

	public static function print_notice(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 !== strpos( $page, 'asem' ) ) {
			return;
		}
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Markup dell'avviso, stringa vuota se la licenza è in regola (o in standby). */
	public static function html(): string {
		if ( ! License::degraded() ) {
			return self::info_html();
		}
		$p   = License::policy();
		$url = License::payment_url();
		$html  = '<div class="notice notice-warning asem-license-notice"><p><strong>Licenza di Associazione Semplice Pro non in regola.</strong> ' . esc_html( LicensePolicy::message( $p['status'] ) ) . '</p>';
		$html .= '<p>Le funzioni avanzate (pagamenti online, conti e fondi, contabilità, comunicazioni, app e quote diverse per i soci) sono sospese; il resto funziona e i tuoi dati sono al sicuro. ';
		$html .= $url
			? '<a class="button button-primary" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Regolarizza la licenza</a>'
			: 'Contatta il fornitore del servizio per regolarizzare la licenza.';
		return $html . '</p></div>';
	}

	/** Inviti che non bloccano nulla: collegare la chiave gratuita, rinnovare una licenza scaduta da pochi giorni. */
	private static function info_html(): string {
		if ( ! License::enabled() ) {
			return '';
		}
		if ( '' === License::key() ) {
			return '<div class="notice notice-info is-dismissible asem-license-notice"><p><strong>Collega la licenza gratuita.</strong> È gratis, legata al dominio di questo sito, e abilita la prova del piano Pro e gli avvisi di aggiornamento. Il plugin funziona anche senza.</p><p><a class="button button-primary" href="' . esc_url( License::free_url() ) . '" target="_blank" rel="noopener">Ottieni la chiave gratuita</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=asem-settings#asem-license' ) ) . '">Ho già una chiave</a></p></div>';
		}
		if ( License::state()['in_grace'] ) {
			return '<div class="notice notice-warning asem-license-notice"><p><strong>La licenza è scaduta da pochi giorni.</strong> C\'è ancora una breve tolleranza: rinnovala per non perdere le funzioni del tuo piano. <a class="button button-primary" href="' . esc_url( License::payment_url() ) . '" target="_blank" rel="noopener">Rinnova la licenza</a></p></div>';
		}
		return '';
	}
}
