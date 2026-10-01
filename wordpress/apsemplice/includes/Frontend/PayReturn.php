<?php
namespace ApSemplice\Frontend;

use ApSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Ritorno del socio dalla pagina di pagamento di Stripe/PayPal: il sito verifica il pagamento DIRETTAMENTE col gateway
 * (non si fida dei parametri dell'indirizzo) e poi riporta il socio alla pagina con l'esito.
 */
final class PayReturn {

	const PARAMS = array( 'aps_pay', 'aps_ret', 'token', 'PayerID', 'paymentId' );

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
	}

	public static function handle(): void {
		if ( ! isset( $_GET['aps_pay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$public = sanitize_text_field( wp_unslash( $_GET['aps_pay'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$ret    = isset( $_GET['aps_ret'] ) && 'ok' === sanitize_key( wp_unslash( $_GET['aps_ret'] ) ) ? 'ok' : 'cancel'; // phpcs:ignore WordPress.Security.NonceVerification
		$url    = Restrict::current_url();
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $url ) ); // dopo l'accesso si torna qui e il pagamento viene verificato
			exit;
		}
		$clean = remove_query_arg( self::PARAMS, $url );
		try {
			$msg = Plugin::payments()->handle_return( $public, $ret, get_current_user_id() );
			wp_safe_redirect( add_query_arg( 'apsf_ok', $msg, $clean ) );
		} catch ( \InvalidArgumentException $e ) {
			wp_safe_redirect( add_query_arg( 'apsf_err', $e->getMessage(), $clean ) );
		} catch ( \Throwable $e ) {
			wp_safe_redirect( add_query_arg( 'apsf_err', 'Non è stato possibile verificare il pagamento. Se hai pagato, lo registreremo appena il gateway lo conferma.', $clean ) );
		}
		exit;
	}
}
