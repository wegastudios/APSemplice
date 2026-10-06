<?php
namespace ApSemplice\Frontend;

use ApSemplice\CardToken;
use ApSemplice\License;
use ApSemplice\Money;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pagina che si apre scansionando il QR di una prenotazione: dice se la prenotazione è valida, per quale evento e data, e se il
 * contributo è stato versato. Esiste solo per gli eventi per cui il gestore ha attivato il "biglietto QR" (scelta per singolo evento).
 */
final class TicketVerify {

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
	}

	public static function handle(): void {
		if ( ! isset( $_GET['apse_ticket'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$flash = ApSemplicelash::read( 'apsf' );
		$html  = self::page( sanitize_text_field( wp_unslash( $_GET['apse_ticket'] ) ), $flash ); // phpcs:ignore WordPress.Security.NonceVerification
		CardVerify::send_headers();
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- già escapato in page()
		exit;
	}

	/**
	 * @return array status (valid|cancelled|past|invalid|suspended), person, session, activity, booking (con lo stato dei pagamenti), today (bool)
	 */
	public static function result( string $param ): array {
		$none   = array( 'person' => null, 'session' => null, 'activity' => null, 'booking' => null, 'today' => false );
		$parsed = CardToken::ticket_parse( $param );
		if ( ! $parsed || ! CardToken::ticket_valid( $parsed[0], $parsed[1], $parsed[2], Settings::card_secret() ) ) {
			return array_merge( $none, array( 'status' => 'invalid' ) );
		}
		if ( ! License::allows( 'member_area' ) ) {
			return array_merge( $none, array( 'status' => 'suspended' ) );
		}
		$acts     = Plugin::activities();
		$session  = $acts->session( $parsed[0] );
		$activity = $session ? $acts->get( (int) $session['activity_id'] ) : null;
		$person   = Plugin::people()->get( $parsed[1] );
		$booking  = $acts->booking( $parsed[0], $parsed[1] );
		if ( ! Settings::tickets_enabled() || ! $session || ! $activity || empty( $activity['booking_qr'] ) || ! $person || ! $booking ) {
			return array_merge( $none, array( 'status' => 'invalid' ) );
		}
		$row = null;
		foreach ( $acts->bookings_for_session( $parsed[0] ) as $b ) {
			if ( (int) $b['person_id'] === $parsed[1] ) {
				$row = $b;
				break;
			}
		}
		$today  = current_time( 'Y-m-d' );
		$status = 'valid';
		if ( 'booked' !== $booking['status'] || ! empty( $session['cancelled_at'] ) ) {
			$status = 'cancelled';
		} elseif ( $session['session_date'] < $today ) {
			$status = 'past';
		} elseif ( ! empty( $booking['checked_in_at'] ) ) {
			$status = 'used';
		}
		return array( 'status' => $status, 'person' => $person, 'session' => $session, 'activity' => $activity, 'booking' => $row, 'today' => $session['session_date'] === $today );
	}

	/** Comandi per chi gestisce l'evento (accesso effettuato): registrare o annullare l'ingresso. Per gli altri, niente. */
	private static function manager_controls( array $r, string $param ): string {
		if ( ! is_user_logged_in() || ! in_array( $r['status'], array( 'valid', 'used' ), true ) || ! current_user_can( 'apse_manage_event', (int) $r['activity']['id'] ) ) {
			return '';
		}
		$s    = $r['session'];
		$back = Settings::ticket_url( (int) $s['id'], (int) $r['person']['id'] );
		if ( 'valid' === $r['status'] && ! $r['today'] && ! current_user_can( \ApSemplice\Plugin::CAP ) ) {
			return '<div class="w">Gli ingressi si registrano nel giorno dell\'evento.</div>';
		}
		$undo = 'used' === $r['status'];
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:14px">'
			. '<input type="hidden" name="action" value="apse_front_checkin"><input type="hidden" name="_back" value="' . esc_url( $back ) . '">'
			. wp_nonce_field( 'apse_front_checkin', '_wpnonce', false, false )
			. '<input type="hidden" name="session_id" value="' . (int) $s['id'] . '"><input type="hidden" name="person_id" value="' . (int) $r['person']['id'] . '">'
			. ( $undo ? '<input type="hidden" name="undo" value="1">' : '' )
			. '<button type="submit" style="width:100%;padding:14px;font-size:18px;border:0;border-radius:10px;cursor:pointer;background:' . ( $undo ? '#dcdcde;color:#1d2327' : '#1a7f37;color:#fff' ) . '">'
			. ( $undo ? 'Annulla la registrazione' : 'Registra ingresso' ) . '</button></form>';
	}

	/** @param array $flash ok, err: esito dell'ultima azione (messaggi) */
	public static function page( string $param, array $flash = array() ): string {
		$r   = self::result( $param );
		$map = array(
			'valid'     => array( 'Prenotazione valida', '#1a7f37' ),
			'used'      => array( 'Ingresso già registrato', '#8a6d00' ),
			'cancelled' => array( 'Prenotazione annullata', '#b32d2e' ),
			'past'      => array( 'Evento già svolto', '#8a6d00' ),
			'invalid'   => array( 'QR non valido', '#b32d2e' ),
			'suspended' => array( 'Servizio sospeso', '#8a6d00' ),
		);
		list( $title, $color ) = $map[ $r['status'] ];
		if ( ! $r['person'] ) {
			$body = 'invalid' === $r['status']
				? '<p>Il codice non corrisponde a nessuna prenotazione attiva. Può essere stato sostituito: chiedi di mostrare quello aggiornato.</p>'
				: '<p>La verifica non è disponibile al momento.</p>';
			return CardVerify::layout( $title, $color, $body );
		}
		$s   = $r['session'];
		$p   = $r['person'];
		$b   = $r['booking'];
		$pay = '';
		if ( $b && (int) $b['fee_due_cents'] > 0 ) {
			$pay = 'paid' === $b['state']
				? '<dd>Versato</dd>'
				: '<dd style="color:#b32d2e">Da versare ' . esc_html( Money::format( (int) $b['remaining'] ) ) . '</dd>';
			$pay = '<div><dt>Contributo</dt>' . $pay . '</div>';
		} elseif ( $b ) {
			$pay = '<div><dt>Contributo</dt><dd>Gratuito</dd></div>';
		}
		$body = '';
		if ( ! empty( $flash['ok'] ) ) {
			$body .= '<div class="w" style="color:#1a7f37">' . esc_html( $flash['ok'] ) . '</div>';
		}
		if ( ! empty( $flash['err'] ) ) {
			$body .= '<div class="w" style="color:#b32d2e">' . esc_html( $flash['err'] ) . '</div>';
		}
		$body .= '<div class="n">' . esc_html( trim( $p['first_name'] . ' ' . $p['last_name'] ) ) . '</div><div class="t">' . esc_html( $r['activity']['name'] ) . '</div>'
			. '<dl><div><dt>Data</dt><dd>' . esc_html( Views::date_long( $s['session_date'] ) ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . '</dd></div>'
			. ( $s['location'] ? '<div><dt>Luogo</dt><dd>' . esc_html( $s['location'] ) . '</dd></div>' : '' ) . $pay
			. ( 'used' === $r['status'] ? '<div><dt>Ingresso</dt><dd>ore ' . esc_html( mysql2date( 'H:i', $b['checked_in_at'] ) ) . '</dd></div>' : '' ) . '</dl>';
		if ( in_array( $r['status'], array( 'valid', 'used' ), true ) && ! $r['today'] ) {
			$body .= '<div class="w">Attenzione: la prenotazione è per un\'altra data.</div>';
		}
		return CardVerify::layout( $title, $color, $body . self::manager_controls( $r, $param ) );
	}
}
