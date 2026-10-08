<?php
namespace ApSemplice\Frontend;

use ApSemplice\AccessRequests;
use ApSemplice\Audit;
use ApSemplice\License;
use ApSemplice\Limits;
use ApSemplice\MemberType;
use ApSemplice\Phone;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * "Primo accesso": il socio scrive nome, email e cellulare e riceve il link per scegliere la password.
 * Risponde sempre allo stesso modo, così non si può scoprire chi è socio.
 */
final class FirstAccess {

	const MAX_PER_HOUR = 10;
	const MESSAGE      = 'Richiesta ricevuta. Se sei un socio ti abbiamo scritto all\'email indicata: apri il messaggio e scegli la tua password (controlla anche la posta indesiderata). Altrimenti ti contatta la segreteria su WhatsApp.';

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
		// Non più di qualche email all'ora allo stesso indirizzo: nessuno può inondare di messaggi la casella di un socio (la risposta del sito resta uguale).
		$mail_key = 'apse_fa_m_' . md5( strtolower( $user->user_email ) );
		$sent     = (int) get_transient( $mail_key );
		if ( $sent >= Limits::get( 'first_access_per_email' ) ) {
			return false;
		}
		set_transient( $mail_key, $sent + 1, HOUR_IN_SECONDS );
		$person = \ApSemplice\Access::person_for_user( (int) $user->ID );
		if ( ! $person || ! MemberType::is_member( $person['type'] ) || user_can( $user, Plugin::CAP_OPS ) ) {
			return false; // solo i soci (mai gli amministratori)
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return false;
		}
		// Chi si attiva dal sito completa indirizzo e codice fiscale dal suo profilo (finché mancano non prenota né paga online).
		if ( Limits::flag( 'profile_required' ) && ! Plugin::people()->profile_complete( $person ) ) {
			Plugin::people()->set_profile_due( (int) $person['id'], true );
		}
		$assoc = (string) Settings::get( 'association_name' );
		$link  = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		$text  = 'Ciao ' . $person['first_name'] . ",\n\necco il link per il tuo primo accesso" . ( '' !== $assoc ? ' a ' . $assoc : '' ) . ":\n\n" . $link
			. "\n\nApri il link, scegli la tua password e poi entra con questa email. Il link vale 24 ore: se scade, ripeti il \"Primo accesso\" dal sito.\n\nSe non hai chiesto tu questo messaggio, ignoralo.";
		return (bool) \ApSemplice\Texts::mail( $user->user_email, 'Primo accesso' . ( '' !== $assoc ? ' — ' . $assoc : '' ), $text );
	}

	/**
	 * Riconosce il socio dall'email o dal cellulare:
	 *  - email già del socio: gli arriva il link;
	 *  - solo il cellulare è di un socio (con o senza accesso): il cellulare da solo non basta a dimostrare chi è, quindi non si crea né si collega nessun accesso da soli: la richiesta va alla segreteria, che verifica e approva;
	 *  - nessuno dei due: la richiesta va alla segreteria.
	 *
	 * @return string emailed | change_queued | unknown_queued | invalid | none
	 */
	public static function submit( string $name, string $email, string $phone ): string {
		if ( ! License::allows( 'member_area' ) ) {
			return 'none';
		}
		$name  = trim( $name );
		$email = trim( $email );
		$phone = trim( $phone );
		if ( '' === $name || ! is_email( $email ) || ! Phone::is_valid( $phone ) ) {
			return 'invalid';
		}
		$people = Plugin::people();
		$queue  = function ( string $kind, int $person_id = 0 ) use ( $name, $email, $phone ) {
			AccessRequests::add( array( 'kind' => $kind, 'person_id' => $person_id, 'name' => $name, 'email' => $email, 'phone' => $phone ) );
		};

		// 1. L'email è già di un socio: caso sicuro, il link va a quell'indirizzo.
		$by_mail = $people->find_by_email( strtolower( $email ) );
		if ( $by_mail && MemberType::is_member( $by_mail['type'] ) ) {
			if ( empty( $by_mail['wp_user_id'] ) ) {
				$people->update( (int) $by_mail['id'], array() ); // crea l'utente che mancava
			}
			return self::request( $email ) ? 'emailed' : 'none';
		}

		// 2. Il cellulare è di un solo socio.
		$members = array();
		foreach ( $people->find_by_phone( $phone ) as $p ) {
			if ( MemberType::is_member( $p['type'] ) ) {
				$members[] = $p;
			}
		}
		if ( 1 === count( $members ) ) {
			$queue( 'change', (int) $members[0]['id'] ); // nessun accesso automatico: lo approva la segreteria dopo aver verificato la persona
			return 'change_queued';
		}

		// 3. Non riconosciuto. Se l'iscrizione è solo su presentazione la richiesta non si registra (la risposta resta la stessa).
		if ( 'invite' !== Settings::get( 'join_mode' ) ) {
			$queue( 'unknown' );
		}
		return 'unknown_queued';
	}

	/** Approva il cambio email chiesto da un socio con accesso già attivo e gli manda il link. */
	public static function approve_change( string $request_id ): void {
		$r = AccessRequests::get( $request_id );
		if ( ! $r || 'change' !== $r['kind'] || ! $r['person_id'] ) {
			throw new \InvalidArgumentException( 'Richiesta non trovata.' );
		}
		Plugin::people()->update( (int) $r['person_id'], array( 'email' => strtolower( $r['email'] ) ) );
		Audit::log( 'person.email_changed_by_request', 'person', (int) $r['person_id'] );
		AccessRequests::remove( $request_id );
		self::request( $r['email'] );
	}

	public static function page( string $message = '', array $post = array(), string $error = '' ): string {
		$accent = (string) Settings::get( 'accent_color' ) ?: '#2271b1';
		$input  = 'width:100%;box-sizing:border-box;padding:10px;font-size:16px;margin:4px 0 12px;border:1px solid #8c8f94;border-radius:8px';
		$field  = function ( string $label, string $name, string $type, string $auto ) use ( $input, $post ) {
			return '<label>' . esc_html( $label ) . '<input style="' . $input . '" type="' . $type . '" name="' . $name . '" value="' . esc_attr( (string) ( $post[ $name ] ?? '' ) ) . '" required autocomplete="' . $auto . '"></label>';
		};
		$body = ( '' !== $message ? '<div class="w" style="color:#1a7f37">' . esc_html( $message ) . '</div>' : '' )
			. ( '' !== $error ? '<div class="w" style="color:#b32d2e">' . esc_html( $error ) . '</div>' : '' )
			. '<div class="n">Primo accesso</div><p>Indica i tuoi dati: ti mandiamo il link per scegliere la password. ' . ( 'invite' === Settings::get( 'join_mode' ) ? 'L\'accesso è riservato a chi è già iscritto: l\'iscrizione si fa su presentazione, rivolgendosi alla segreteria.' : 'Se non ti riconosciamo, la richiesta arriva alla segreteria, che ti scrive su WhatsApp.' ) . '</p>'
			. '<form method="post" action="' . esc_url( self::url() ) . '">' . wp_nonce_field( 'apse_first_access', '_apse_nonce', false, false ) . '<input type="hidden" name="apse_first_go" value="1">'
			. $field( 'Nome e cognome', 'name', 'text', 'name' ) . $field( 'La tua email', 'email', 'email', 'email' ) . $field( 'Il tuo cellulare', 'phone', 'tel', 'tel' )
			. '<button type="submit" style="width:100%;padding:14px;font-size:18px;border:0;border-radius:10px;cursor:pointer;background:' . esc_attr( $accent ) . ';color:#fff">Mandami il link</button></form>'
			. '<p style="margin-top:14px"><a href="' . esc_url( wp_login_url() ) . '">← Torna all\'accesso</a></p>';
		return CardVerify::layout( 'Primo accesso', $accent, $body );
	}

	public static function handle(): void {
		if ( ! isset( $_GET['apse_first_access'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$message = '';
		$error   = '';
		$post    = array();
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['apse_first_go'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- valore verificato e ripulito da chi lo usa
			$post   = array_map( 'sanitize_text_field', wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$rl_key = 'apse_fa_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- valore verificato e ripulito da chi lo usa
			$tries  = (int) get_transient( $rl_key );
			if ( $tries >= Limits::get( 'first_access_per_ip' ) ) {
				$error = 'Troppi tentativi: riprova tra qualche minuto.';
			} elseif ( ! wp_verify_nonce( (string) ( $post['_apse_nonce'] ?? '' ), 'apse_first_access' ) ) {
				$error = 'Sessione scaduta: riprova.';
			} else {
				set_transient( $rl_key, $tries + 1, HOUR_IN_SECONDS );
				if ( 'invalid' === self::submit( (string) ( $post['name'] ?? '' ), (string) ( $post['email'] ?? '' ), (string) ( $post['phone'] ?? '' ) ) ) {
					$error = 'Controlla i dati: servono nome e cognome, un\'email valida e il numero di cellulare.';
				} else {
					$message = self::MESSAGE; // sempre la stessa risposta: non si scopre chi è socio
					$post    = array();
				}
			}
		}
		CardVerify::send_headers();
		echo self::page( $message, $post, $error ); // phpcs:ignore WordPress.Security.EscapeOutput -- già escapato in page()
		exit;
	}
}
