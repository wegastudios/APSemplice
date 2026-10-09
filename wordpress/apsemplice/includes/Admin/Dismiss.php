<?php
namespace ApSemplice\Admin;

use ApSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Avvisi che si possono chiudere (assicurazioni, copia di sicurezza, 5x1000…). La chiusura vale per chi l'ha fatta e per un certo numero di
 * giorni: poi, se la situazione non è cambiata, l'avviso ricompare. Niente avviso, niente rumore: si chiude con la X e basta.
 */
final class Dismiss {

	const META = 'apse_dismissed_notices';
	const NONCE = 'apse_dismiss';

	public static function register(): void {
		add_action( 'wp_ajax_apse_dismiss_notice', array( __CLASS__, 'ajax' ) );
	}

	/** @return array<string,int> chiave => scadenza (timestamp) */
	private static function all(): array {
		$m = get_user_meta( get_current_user_id(), self::META, true );
		return is_array( $m ) ? $m : array();
	}

	public static function is_dismissed( string $key ): bool {
		$a = self::all();
		return isset( $a[ $key ] ) && (int) $a[ $key ] > time();
	}

	public static function dismiss( string $key, int $days ): void {
		$a = array_filter(
			self::all(),
			function ( $until ) {
				return (int) $until > time(); // si dimenticano le chiusure scadute
			}
		);
		$a[ $key ] = time() + max( 1, min( 365, $days ) ) * DAY_IN_SECONDS;
		update_user_meta( get_current_user_id(), self::META, $a );
	}

	/**
	 * Avviso chiudibile (stringa vuota se è già stato chiuso).
	 *
	 * @param string $key   nome stabile dell'avviso; se cambia la situazione si può cambiare (es. con un numero) perché ricompaia
	 * @param string $type  warning | info | error
	 * @param string $inner HTML già protetto da mettere nel paragrafo
	 * @param int    $days  per quanti giorni resta chiuso
	 */
	public static function html( string $key, string $type, string $inner, int $days = 30 ): string {
		if ( self::is_dismissed( $key ) ) {
			return '';
		}
		return '<div class="notice notice-' . esc_attr( $type ) . ' inline is-dismissible apse-dismissible" data-apse-key="' . esc_attr( $key ) . '" data-apse-days="' . (int) $days . '" data-apse-nonce="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '"><p>' . $inner . '</p></div>';
	}

	public static function ajax(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( Plugin::CAP_OPS ) ) {
			wp_send_json_error( 'Non autorizzato', 403 );
		}
		$key  = isset( $_POST['key'] ) ? substr( sanitize_key( wp_unslash( $_POST['key'] ) ), 0, 80 ) : '';
		$days = isset( $_POST['days'] ) ? (int) $_POST['days'] : 30;
		if ( '' === $key ) {
			wp_send_json_error( 'Avviso non valido', 400 );
		}
		self::dismiss( $key, $days );
		wp_send_json_success();
	}
}
