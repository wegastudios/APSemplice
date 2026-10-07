<?php
namespace ApSemplice\Frontend;

use ApSemplice\ActivationToken;
use ApSemplice\License;
use ApSemplice\Limits;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Attivazione dell'accesso per i soci registrati senza email: il socio apre il suo link (ricevuto su WhatsApp), indica email,
 * cellulare e password, e da quel momento ha il suo accesso all'area riservata.
 */
final class Activation {

	const MAX_ATTEMPTS_PER_HOUR = 15;

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
	}

	private static function secret(): string {
		return wp_salt( 'auth' ) . '|activate';
	}

	/** Link di attivazione di un socio (valido 30 giorni da adesso). */
	public static function url( int $person_id, ?int $now = null ): string {
		$exp = ( $now ?? time() ) + Limits::get( 'activation_days' ) * DAY_IN_SECONDS;
		return add_query_arg( 'apse_activate', ActivationToken::param( $person_id, $exp, self::secret() ), home_url( '/' ) );
	}

	/** Messaggio di invito per WhatsApp con il link già dentro. */
	public static function invite_text( array $person ): string {
		$assoc = (string) Settings::get( 'association_name' );
		return 'Ciao ' . $person['first_name'] . ', sei stato/a registrato/a come socio/a' . ( '' !== $assoc ? ' di ' . $assoc : '' ) . '. '
			. 'Attiva il tuo accesso all\'area riservata da questo link (vale ' . Limits::get( 'activation_days' ) . ' giorni): ' . self::url( (int) $person['id'] );
	}

	/** @return array status (ok|invalid|expired|done|suspended), person */
	public static function resolve( string $param, ?int $now = null ): array {
		$state = ActivationToken::check( $param, self::secret(), $now ?? time() );
		if ( 'ok' !== $state ) {
			return array( 'status' => $state, 'person' => null );
		}
		if ( ! License::allows( 'member_area' ) ) {
			return array( 'status' => 'suspended', 'person' => null );
		}
		$p = Plugin::people()->get( ActivationToken::parse( $param )[0] );
		if ( ! $p || ! \ApSemplice\MemberType::is_member( $p['type'] ) ) {
			return array( 'status' => 'invalid', 'person' => null );
		}
		return array( 'status' => ! empty( $p['wp_user_id'] ) ? 'done' : 'ok', 'person' => $p );
	}

	private static function message( string $status ): string {
		switch ( $status ) {
			case 'expired':
				return 'Il link è scaduto: chiedi alla segreteria di mandartene uno nuovo.';
			case 'done':
				return 'Il tuo accesso è già attivo: entra con la tua email e, se hai dimenticato la password, usa "Password dimenticata".';
			case 'suspended':
				return 'Il servizio è temporaneamente sospeso. Riprova più tardi.';
		}
		return 'Il link non è valido: chiedi alla segreteria di mandartene uno nuovo.';
	}

	/**
	 * Dati del profilo scritti dal socio nel modulo di attivazione. Con l'obbligo attivo (Limits «profile_required») servono codice fiscale valido,
	 * indirizzo, CAP e comune; se la segreteria aveva già registrato il codice fiscale, quello scritto deve coincidere (è un secondo controllo:
	 * un link ricevuto per errore non basta per attivare l'accesso di un altro socio).
	 *
	 * @return array campi da salvare (vuoto se non ci sono dati e non sono obbligatori)
	 * @throws \InvalidArgumentException
	 */
	public static function profile_input( array $post, array $person ): array {
		$cf   = \ApSemplice\TaxCode::normalize( (string) ( $post['tax_code'] ?? '' ) );
		$in   = array(
			'tax_code' => $cf, 'address' => trim( (string) ( $post['address'] ?? '' ) ), 'zip' => trim( (string) ( $post['zip'] ?? '' ) ),
			'city'     => trim( (string) ( $post['city'] ?? '' ) ), 'province' => trim( (string) ( $post['province'] ?? '' ) ),
		);
		$need = Limits::flag( 'profile_required' );
		if ( $need && '' === $cf ) {
			throw new \InvalidArgumentException( 'Il codice fiscale è obbligatorio.' );
		}
		if ( '' !== $cf && ! \ApSemplice\TaxCode::is_valid( $cf ) ) {
			throw new \InvalidArgumentException( 'Il codice fiscale non è valido: controllalo (16 caratteri).' );
		}
		$known = \ApSemplice\TaxCode::normalize( (string) ( $person['tax_code'] ?? '' ) );
		if ( '' !== $known && '' !== $cf && $known !== $cf ) {
			throw new \InvalidArgumentException( 'Il codice fiscale non corrisponde a quello registrato dalla segreteria: controllalo o scrivi alla segreteria.' );
		}
		if ( $need ) {
			foreach ( array( 'address' => 'l\'indirizzo', 'zip' => 'il CAP', 'city' => 'il comune' ) as $k => $label ) {
				if ( '' === $in[ $k ] ) {
					throw new \InvalidArgumentException( 'Indica ' . $label . '.' );
				}
			}
		}
		if ( '' !== $in['zip'] && ! preg_match( '/^[0-9A-Za-z\- ]{3,12}$/', $in['zip'] ) ) {
			throw new \InvalidArgumentException( 'Il CAP non è valido.' );
		}
		return array_filter( $in, function ( $v ) {
			return '' !== $v;
		} );
	}

	/** @return array ok (bool), error, user_id */
	public static function complete( string $param, array $post ): array {
		$r = self::resolve( $param );
		if ( 'ok' !== $r['status'] ) {
			return array( 'ok' => false, 'error' => self::message( $r['status'] ) );
		}
		if ( \ApSemplice\Regulation::enabled() && empty( $post['rules_ok'] ) ) {
			return array( 'ok' => false, 'error' => 'Per continuare devi accettare il ' . mb_strtolower( \ApSemplice\Regulation::title(), 'UTF-8' ) . '.' );
		}
		if ( '' !== (string) Settings::get( 'privacy_url' ) && empty( $post['privacy_ok'] ) ) {
			return array( 'ok' => false, 'error' => 'Per continuare devi accettare l\'informativa sulla privacy.' );
		}
		if ( (string) ( $post['password'] ?? '' ) !== (string) ( $post['password2'] ?? '' ) ) {
			return array( 'ok' => false, 'error' => 'Le due password non coincidono.' );
		}
		try {
			$profile = self::profile_input( $post, $r['person'] ); // indirizzo e codice fiscale: obbligatori; il codice fiscale deve coincidere con quello registrato dalla segreteria
			$uid     = Plugin::people()->activate( (int) $r['person']['id'], (string) ( $post['email'] ?? '' ), (string) ( $post['phone'] ?? '' ), (string) ( $post['password'] ?? '' ) );
			if ( $profile ) {
				Plugin::people()->update( (int) $r['person']['id'], $profile );
			}
		} catch ( \InvalidArgumentException $e ) {
			return array( 'ok' => false, 'error' => $e->getMessage() );
		}
		if ( '' !== (string) Settings::get( 'privacy_url' ) ) {
			\ApSemplice\Privacy::set_consent( (int) $r['person']['id'], 'web' );
		}
		if ( \ApSemplice\Regulation::enabled() ) {
			\ApSemplice\Regulation::accept( (int) $r['person']['id'], 'web' );
		}
		return array( 'ok' => true, 'user_id' => $uid );
	}

	public static function page( string $param, array $post = array(), string $error = '' ): string {
		$r      = self::resolve( $param );
		$accent = (string) Settings::get( 'accent_color' ) ?: '#2271b1';
		if ( 'ok' !== $r['status'] ) {
			return CardVerify::layout( 'Attivazione non disponibile', '#b32d2e', '<p>' . esc_html( self::message( $r['status'] ) ) . '</p>' );
		}
		$p     = $r['person'];
		$input = 'width:100%;box-sizing:border-box;padding:10px;font-size:16px;margin:4px 0 12px;border:1px solid #8c8f94;border-radius:8px';
		$need  = Limits::flag( 'profile_required' );
		$body  = ( '' !== $error ? '<div class="w" style="color:#b32d2e">' . esc_html( $error ) . '</div>' : '' )
			. '<div class="n">Ciao ' . esc_html( $p['first_name'] ) . '!</div><p>Scegli l\'email e la password con cui entrerai nell\'area riservata.</p>'
			. '<form method="post" action="' . esc_url( add_query_arg( 'apse_activate', $param, home_url( '/' ) ) ) . '" autocomplete="on">'
			. '<input type="hidden" name="apse_activate_go" value="1">' . wp_nonce_field( 'apse_activate_' . (int) $p['id'], '_apse_nonce', false, false )
			. '<label>La tua email<input style="' . $input . '" type="email" name="email" value="' . esc_attr( (string) ( $post['email'] ?? '' ) ) . '" required autocomplete="email"></label>'
			. '<label>Il tuo cellulare<input style="' . $input . '" type="tel" name="phone" value="' . esc_attr( (string) ( $post['phone'] ?? $p['phone'] ?? '' ) ) . '" required autocomplete="tel"></label>'
			. '<p class="a" style="margin:4px 0 8px">I tuoi dati' . ( $need ? ' (obbligatori)' : '' ) . ': servono per le ricevute e per verificare che sei proprio tu.</p>'
			. '<label>Codice fiscale<input style="' . $input . '" type="text" name="tax_code" maxlength="16" ' . ( $need ? 'required ' : '' ) . 'autocomplete="off" value="' . esc_attr( (string) ( $post['tax_code'] ?? '' ) ) . '"></label>'
			. '<label>Indirizzo (via e numero)<input style="' . $input . '" type="text" name="address" maxlength="190" ' . ( $need ? 'required ' : '' ) . 'autocomplete="street-address" value="' . esc_attr( (string) ( $post['address'] ?? $p['address'] ?? '' ) ) . '"></label>'
			. '<label>CAP<input style="' . $input . '" type="text" name="zip" maxlength="12" ' . ( $need ? 'required ' : '' ) . 'autocomplete="postal-code" value="' . esc_attr( (string) ( $post['zip'] ?? $p['zip'] ?? '' ) ) . '"></label>'
			. '<label>Comune<input style="' . $input . '" type="text" name="city" maxlength="100" ' . ( $need ? 'required ' : '' ) . 'autocomplete="address-level2" value="' . esc_attr( (string) ( $post['city'] ?? $p['city'] ?? '' ) ) . '"></label>'
			. '<label>Provincia (facoltativa)<input style="' . $input . '" type="text" name="province" maxlength="5" autocomplete="address-level1" value="' . esc_attr( (string) ( $post['province'] ?? $p['province'] ?? '' ) ) . '"></label>'
			. '<label>Password (almeno 8 caratteri)<input style="' . $input . '" type="password" name="password" minlength="8" required autocomplete="new-password"></label>'
			. '<label>Ripeti la password<input style="' . $input . '" type="password" name="password2" minlength="8" required autocomplete="new-password"></label>'
			. ( \ApSemplice\Regulation::enabled() ? '<div style="margin:8px 0"><strong>' . esc_html( \ApSemplice\Regulation::title() ) . '</strong>' . \ApSemplice\Regulation::box_html() . '<label style="display:block;margin:6px 0 14px"><input type="checkbox" name="rules_ok" value="1" required> Ho letto e accetto il ' . esc_html( mb_strtolower( \ApSemplice\Regulation::title(), 'UTF-8' ) ) . '.</label></div>' : '' )
			. ( '' !== (string) Settings::get( 'privacy_url' ) ? '<label style="display:block;margin:6px 0 14px"><input type="checkbox" name="privacy_ok" value="1" required> Ho letto <a href="' . esc_url( (string) Settings::get( 'privacy_url' ) ) . '" target="_blank" rel="noopener">l\'informativa sulla privacy</a> e acconsento al trattamento dei miei dati.</label>' : '' )
			. '<button type="submit" style="width:100%;padding:14px;font-size:18px;border:0;border-radius:10px;cursor:pointer;background:' . esc_attr( $accent ) . ';color:#fff">Attiva il mio accesso</button></form>';
		return CardVerify::layout( 'Attiva il tuo accesso', $accent, $body );
	}

	public static function handle(): void {
		if ( ! isset( $_GET['apse_activate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$param = sanitize_text_field( wp_unslash( $_GET['apse_activate'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$post  = array();
		$error = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['apse_activate_go'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$post    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
			$parsed  = ActivationToken::parse( $param );
			$rl_key  = 'apse_act_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
			$tries   = (int) get_transient( $rl_key );
			if ( $tries >= Limits::get( 'activation_per_ip' ) ) {
				$error = 'Troppi tentativi: riprova tra qualche minuto.';
			} elseif ( ! $parsed || ! wp_verify_nonce( (string) ( $post['_apse_nonce'] ?? '' ), 'apse_activate_' . $parsed[0] ) ) {
				$error = 'Sessione scaduta: riprova.';
			} else {
				set_transient( $rl_key, $tries + 1, HOUR_IN_SECONDS );
				$res = self::complete( $param, $post );
				if ( $res['ok'] ) {
					wp_set_current_user( (int) $res['user_id'] );
					wp_set_auth_cookie( (int) $res['user_id'], true );
					$page = (int) Settings::get( 'member_area_page_id' );
					$url  = $page ? (string) get_permalink( $page ) : home_url( '/' );
					wp_safe_redirect( \ApSemplice\Flash::url( $url, 'apsf', 'Accesso attivato: benvenuto/a!' ) );
					exit;
				}
				$error = $res['error'];
			}
		}
		CardVerify::send_headers();
		echo self::page( $param, $post, $error ); // phpcs:ignore WordPress.Security.EscapeOutput -- già escapato in page()
		exit;
	}
}
