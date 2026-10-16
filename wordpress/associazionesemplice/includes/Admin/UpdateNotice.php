<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\UpdateCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Avviso chiudibile «È disponibile una nuova versione» in cima alle pagine del plugin. Una chiusura vale 30 giorni per quella versione:
 * se ne esce una più recente l'avviso ricompare. Non blocca niente.
 */
final class UpdateNotice {

	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'print_notice' ) );
	}

	public static function print_notice(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 !== strpos( $page, 'asem' ) || ! current_user_can( Plugin::CAP_OPS ) ) {
			return;
		}
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Markup degli avvisi (uno per prodotto da aggiornare), stringa vuota se non c'è niente da segnalare. */
	public static function html(): string {
		$out = '';
		foreach ( UpdateCheck::pending() as $u ) {
			$out .= Dismiss::html(
				'update-' . $u['product'] . '-' . $u['latest'],
				'info',
				'<strong>È disponibile la versione ' . esc_html( $u['latest'] ) . ' di ' . esc_html( $u['label'] ) . '</strong> (hai la ' . esc_html( $u['installed'] ) . '). '
				. 'Scaricala dalla tua area clienti e carica il nuovo pacchetto da Plugin → Aggiungi nuovo: i dati restano. '
				. '<a class="button button-primary" href="' . esc_url( UpdateCheck::download_url( $u ) ) . '" target="_blank" rel="noopener">Scarica l\'aggiornamento</a>',
				30
			);
		}
		return $out;
	}
}
