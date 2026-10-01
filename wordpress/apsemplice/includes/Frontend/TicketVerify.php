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
		$html = self::page( sanitize_text_field( wp_unslash( $_GET['apse_ticket'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
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
		if ( ! $session || ! $activity || empty( $activity['booking_qr'] ) || ! $person || ! $booking ) {
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
		}
		return array( 'status' => $status, 'person' => $person, 'session' => $session, 'activity' => $activity, 'booking' => $row, 'today' => $session['session_date'] === $today );
	}

	public static function page( string $param ): string {
		$r   = self::result( $param );
		$map = array(
			'valid'     => array( 'Prenotazione valida', '#1a7f37' ),
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
		$body = '<div class="n">' . esc_html( trim( $p['first_name'] . ' ' . $p['last_name'] ) ) . '</div><div class="t">' . esc_html( $r['activity']['name'] ) . '</div>'
			. '<dl><div><dt>Data</dt><dd>' . esc_html( Views::date_long( $s['session_date'] ) ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . '</dd></div>'
			. ( $s['location'] ? '<div><dt>Luogo</dt><dd>' . esc_html( $s['location'] ) . '</dd></div>' : '' ) . $pay . '</dl>';
		if ( 'valid' === $r['status'] && ! $r['today'] ) {
			$body .= '<div class="w">Attenzione: la prenotazione è per un\'altra data.</div>';
		}
		return CardVerify::layout( $title, $color, $body );
	}
}
