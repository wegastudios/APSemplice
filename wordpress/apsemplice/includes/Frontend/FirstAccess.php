<?php
namespace ApSemplice\Frontend;

use ApSemplice\License;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * "Primo accesso": il socio scrive la sua email e riceve il link per scegliere la password. È il recupero password di WordPress,
 * ma con il nome e le parole giuste per chi entra per la prima volta (i soci importati hanno un utente senza password nota).
 * Risponde sempre allo stesso modo, così non si può scoprire quali email sono di soci.
 */
final class FirstAccess {

	const MAX_PER_HOUR = 10;
	const MESSAGE      = 'Se l\'email è quella di un socio, ti abbiamo scritto: apri il messaggio e scegli la tua password. Controlla anche la posta indesiderata. Non hai ricevuto nulla o non hai un\'email registrata? Chiedi alla segreteria.';

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
		add_filter( 'login_message', array( __CLASS__, 'login_message' ) );
	}

	public static function url(): string {
		return add_query_arg( 'apse_first_access', '1', home_url( '/' ) );
	}

	/** Avviso sopra il modulo di accesso di WordPress. */
	public static function login_message( $message ) {
		return (string) $message . '<p class="message">Sei un socio e non hai ancora una password? <a href="' . esc_url( self::url() ) . '"><strong>Primo accesso</strong></a></p>';
	}

	/**
	 * Manda il link per scegliere la password se l'email è di un socio con accesso. @return bool true se è stata inviata una email
	 */
	public static function request( string $email ): bool {
		if ( ! License::allows( 'member_area' ) ) {
			return false;
		}
		$email = trim( $email );
		$user  = '' !== $email && is_email( $email ) ? get_user_by( 'email', $email ) : false;
		if ( ! $user ) {
			return false;
		}
		$person = \ApSemplice\Access::person_for_user( (int) $user->ID );
		if ( ! $person || ! \ApSemplice\MemberType::is_member( $person['type'] ) || user_can( $user, Plugin::CAP ) ) {
			return false; // solo i soci (mai gli amministratori)
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return false;
		}
		$assoc = (string) Settings::get( 'association_name' );
		$link  = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		$text  = 'Ciao ' . $person['first_name'] . ",\n\necco il link per il tuo primo accesso" . ( '' !== $assoc ? ' a ' . $assoc : '' ) . ":\n\n" . $link
			. "\n\nApri il link, scegli la tua password e poi entra con questa email. Il link vale 24 ore: se scade, ripeti il \"Primo accesso\" dal sito.\n\nSe non hai chiesto tu questo messaggio, ignoralo.";
		return (bool) wp_mail( $user->user_email, 'Primo accesso' . ( '' !== $assoc ? ' — ' . $assoc : '' ), $text );
	}

	public static function page( string $message = '', string $email = '' ): string {
		$accent = (string) Settings::get( 'accent_color' ) ?: '#2271b1';
		$input  = 'width:100%;box-sizing:border-box;padding:10px;font-size:16px;margin:4px 0 12px;border:1px solid #8c8f94;border-radius:8px';
		$body   = ( '' !== $message ? '<div class="w" style="color:#1a7f37">' . esc_html( $message ) . '</div>' : '' )
			. '<div class="n">Primo accesso</div><p>Scrivi l\'email con cui sei registrato come socio: ti mandiamo il link per scegliere la tua password.</p>'
			. '<form method="post" action="' . esc_url( self::url() ) . '">' . wp_nonce_field( 'apse_first_access', '_apse_nonce', false, false ) . '<input type="hidden" name="apse_first_go" value="1">'
			. '<label>La tua email<input style="' . $input . '" type="email" name="email" value="' . esc_attr( $email ) . '" required autocomplete="email"></label>'
			. '<button type="submit" style="width:100%;padding:14px;font-size:18px;border:0;border-radius:10px;cursor:pointer;background:' . esc_attr( $accent ) . ';color:#fff">Mandami il link</button></form>'
			. '<p style="margin-top:14px"><a href="' . esc_url( wp_login_url() ) . '">← Torna all\'accesso</a></p>';
		return CardVerify::layout( 'Primo accesso', $accent, $body );
	}

	public static function handle(): void {
		if ( ! isset( $_GET['apse_first_access'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$message = '';
		$email   = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['apse_first_go'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
			$email  = sanitize_email( (string) ( $post['email'] ?? '' ) );
			$rl_key = 'apse_fa_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
			$tries  = (int) get_transient( $rl_key );
			if ( $tries >= self::MAX_PER_HOUR ) {
				$message = 'Troppi tentativi: riprova tra un po\'.';
			} elseif ( ! wp_verify_nonce( (string) ( $post['_apse_nonce'] ?? '' ), 'apse_first_access' ) ) {
				$message = 'Sessione scaduta: riprova.';
			} else {
				set_transient( $rl_key, $tries + 1, HOUR_IN_SECONDS );
				self::request( $email );
				$message = self::MESSAGE;
				$email   = '';
			}
		}
		CardVerify::send_headers();
		echo self::page( $message, $email ); // phpcs:ignore WordPress.Security.EscapeOutput -- già escapato in page()
		exit;
	}
}
