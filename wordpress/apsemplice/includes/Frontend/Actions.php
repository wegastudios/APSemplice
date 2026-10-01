<?php
namespace ApSemplice\Frontend;

use ApSemplice\Access;
use ApSemplice\MemberType;
use ApSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Azioni dei soci dal sito (prenota, annulla, aggiungi un ospite, aggiorna il profilo).
 * La logica sta nei metodi `do_*` (lanciano \InvalidArgumentException con un messaggio leggibile);
 * il resto è solo il collegamento con admin-post.php, nonce e ritorno alla pagina di partenza.
 * Ogni azione controlla i permessi con le capability meta di {@see Access}: quindi vale anche la licenza.
 */
final class Actions {

	const MAP = array(
		'aps_front_book'           => 'do_book',
		'aps_front_cancel_booking' => 'do_cancel_booking',
		'aps_front_transfer_booking' => 'do_transfer_booking',
		'aps_front_add_guest'      => 'do_add_guest',
		'aps_front_profile'        => 'do_profile',
	);

	public static function register(): void {
		foreach ( self::MAP as $action => $method ) {
			add_action(
				'admin_post_' . $action,
				function () use ( $action, $method ) {
					check_admin_referer( $action );
					$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
					$back = self::back_url( $post );
					try {
						self::redirect( $back, self::$method( $post ) );
					} catch ( \InvalidArgumentException $e ) {
						self::redirect( $back, '', $e->getMessage() );
					} catch ( \Throwable $e ) {
						self::redirect( $back, '', 'Operazione non riuscita. Riprova più tardi.' );
					}
				}
			);
			// Chi non ha fatto l'accesso viene mandato al login e poi riportato alla pagina.
			add_action(
				'admin_post_nopriv_' . $action,
				function () {
					wp_safe_redirect( wp_login_url( self::back_url( wp_unslash( $_POST ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
					exit;
				}
			);
		}
	}

	private static function back_url( array $post ): string {
		$url = ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : home_url( '/' );
		return wp_validate_redirect( $url, home_url( '/' ) );
	}

	private static function redirect( string $url, string $ok = '', string $err = '' ): void {
		$url = remove_query_arg( array( 'apsf_ok', 'apsf_err' ), $url );
		wp_safe_redirect( add_query_arg( '' !== $err ? array( 'apsf_err' => $err ) : array( 'apsf_ok' => $ok ), $url ) );
		exit;
	}

	/** La persona collegata all'utente, solo se è un socio (non un ospite). */
	private static function actor(): array {
		$p = Access::current_person();
		if ( ! $p || ! MemberType::is_member( $p['type'] ) ) {
			throw new \InvalidArgumentException( 'Serve un accesso come socio.' );
		}
		return $p;
	}

	private static function require_cap( string $ability, int $object_id ): void {
		if ( ! current_user_can( $ability, $object_id ) ) {
			throw new \InvalidArgumentException( 'Non hai il permesso di fare questa operazione.' );
		}
	}

	/** Prenota sé stessi o un proprio ospite. @return string messaggio */
	public static function do_book( array $post ): string {
		$actor     = self::actor();
		$person_id = (int) ( $post['person_id'] ?? $actor['id'] );
		self::require_cap( 'aps_book_for', $person_id );
		if ( ! Plugin::people()->is_active_member( (int) $actor['id'] ) ) {
			throw new \InvalidArgumentException( 'La tua tessera non è valida: rinnovala per prenotare.' );
		}
		$session = Plugin::activities()->session( (int) ( $post['session_id'] ?? 0 ) );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Evento non trovato.' );
		}
		if ( $session['session_date'] < current_time( 'Y-m-d' ) ) {
			throw new \InvalidArgumentException( 'Questo evento è già passato.' );
		}
		Plugin::activities()->book( (int) $session['id'], $person_id );
		$person = Plugin::people()->get( $person_id );
		return 'Prenotazione registrata' . ( $person ? ' per ' . $person['first_name'] : '' ) . '.';
	}

	/** Annulla una prenotazione se la regola lo consente: gratis sempre; a pagamento solo se l'evento è cancellabile e nei termini. */
	public static function do_cancel_booking( array $post ): string {
		$actor     = self::actor();
		$person_id = (int) ( $post['person_id'] ?? $actor['id'] );
		self::require_cap( 'aps_book_for', $person_id );
		$session_id = (int) ( $post['session_id'] ?? 0 );
		if ( ! Plugin::activities()->session( $session_id ) ) {
			throw new \InvalidArgumentException( 'Evento non trovato.' );
		}
		$eval = Plugin::activities()->cancellation_for( $session_id, $person_id );
		if ( ! $eval['allowed'] ) {
			throw new \InvalidArgumentException( $eval['message'] );
		}
		Plugin::activities()->cancel_booking( $session_id, $person_id );
		return 'Prenotazione annullata.';
	}

	/**
	 * Cambia il nominativo di una prenotazione: a un proprio ospite già inserito, a sé stessi, oppure a un nuovo ospite
	 * (nome e cognome). Se il nuovo partecipante ha un contributo maggiore la differenza resta da pagare.
	 */
	public static function do_transfer_booking( array $post ): string {
		$actor      = self::actor();
		$from_id    = (int) ( $post['person_id'] ?? $actor['id'] );
		$session_id = (int) ( $post['session_id'] ?? 0 );
		self::require_cap( 'aps_book_for', $from_id );
		if ( ! Plugin::people()->is_active_member( (int) $actor['id'] ) ) {
			throw new \InvalidArgumentException( 'La tua tessera non è valida: rinnovala per gestire le prenotazioni.' );
		}
		$to_id = (int) ( $post['to_person_id'] ?? 0 );
		$first = trim( (string) ( $post['new_first_name'] ?? '' ) );
		$last  = trim( (string) ( $post['new_last_name'] ?? '' ) );
		if ( ! $to_id && ( '' !== $first || '' !== $last ) ) {
			self::require_cap( 'aps_add_guest', (int) $actor['id'] );
			$to_id = Plugin::people()->create( array( 'type' => MemberType::GUEST, 'host_person_id' => (int) $actor['id'], 'first_name' => $first, 'last_name' => $last ) );
		}
		if ( ! $to_id ) {
			throw new \InvalidArgumentException( 'Scegli a chi intestare la prenotazione, oppure indica nome e cognome di un nuovo ospite.' );
		}
		self::require_cap( 'aps_book_for', $to_id );
		Plugin::activities()->transfer_booking( $session_id, $from_id, $to_id, true );
		$b   = Plugin::activities()->bookings_for_session( $session_id );
		$msg = 'Nominativo cambiato.';
		foreach ( $b as $row ) {
			if ( (int) $row['person_id'] === $to_id && $row['remaining'] > 0 ) {
				$msg .= ' Da integrare: ' . \ApSemplice\Money::format( (int) $row['remaining'] ) . ' (si paga in sede).';
			}
		}
		return $msg;
	}

	public static function do_add_guest( array $post ): string {
		$actor = self::actor();
		self::require_cap( 'aps_add_guest', (int) $actor['id'] );
		Plugin::people()->create(
			array(
				'type'           => MemberType::GUEST,
				'host_person_id' => (int) $actor['id'],
				'first_name'     => $post['first_name'] ?? '',
				'last_name'      => $post['last_name'] ?? '',
				'email'          => $post['email'] ?? '',
				'phone'          => $post['phone'] ?? '',
			)
		);
		return 'Ospite aggiunto.';
	}

	public static function do_profile( array $post ): string {
		$actor = self::actor();
		self::require_cap( 'aps_edit_own_profile', (int) $actor['id'] );
		Plugin::people()->update( (int) $actor['id'], array( 'phone' => $post['phone'] ?? '', 'tax_code' => $post['tax_code'] ?? '' ) );
		return 'Profilo aggiornato.';
	}
}
