<?php
namespace ApSemplice\Frontend;

use ApSemplice\Access;
use ApSemplice\Attachments;
use ApSemplice\CardToken;
use ApSemplice\MemberType;
use ApSemplice\Money;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Azioni dei soci dal sito (prenota, annulla, aggiungi un ospite, aggiorna il profilo).
 * La logica sta nei metodi `do_*` (lanciano \InvalidArgumentException con un messaggio leggibile);
 * il resto è solo il collegamento con admin-post.php, nonce e ritorno alla pagina di partenza.
 * Ogni azione controlla i permessi con le capability meta di {@see Access}: quindi vale anche la licenza.
 */
final class Actions {

	const MAP = array(
		'apse_front_book'           => 'do_book',
		'apse_front_cancel_booking' => 'do_cancel_booking',
		'apse_front_transfer_booking' => 'do_transfer_booking',
		'apse_front_add_guest'      => 'do_add_guest',
		'apse_front_profile'        => 'do_profile',
		'apse_front_expense'        => 'do_expense',
		'apse_front_expense_docs'   => 'do_expense_docs',
		'apse_front_checkin'        => 'do_checkin',
		'apse_front_checkin_scan'   => 'do_checkin_scan',
		'apse_front_door'           => 'do_door',
		'apse_front_notice'         => 'do_notice',
	);

	public static function register(): void {
		// Pagamento online: la risposta è un indirizzo esterno (pagina del gateway), non un messaggio.
		add_action(
			'admin_post_apse_front_pay',
			function () {
				check_admin_referer( 'apse_front_pay' );
				$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
				$back = self::back_url( $post );
				try {
					wp_redirect( self::do_pay( $post ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- indirizzo restituito dal gateway
				} catch ( \InvalidArgumentException $e ) {
					self::redirect( $back, '', $e->getMessage() );
				} catch ( \Throwable $e ) {
					self::redirect( $back, '', 'Operazione non riuscita. Riprova più tardi.' );
				}
				exit;
			}
		);
		add_action(
			'admin_post_nopriv_apse_front_pay',
			function () {
				wp_safe_redirect( wp_login_url( self::back_url( wp_unslash( $_POST ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
				exit;
			}
		);
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
		self::require_cap( 'apse_book_for', $person_id );
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
		self::require_cap( 'apse_book_for', $person_id );
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
		self::require_cap( 'apse_book_for', $from_id );
		if ( ! Plugin::people()->is_active_member( (int) $actor['id'] ) ) {
			throw new \InvalidArgumentException( 'La tua tessera non è valida: rinnovala per gestire le prenotazioni.' );
		}
		$to_id = (int) ( $post['to_person_id'] ?? 0 );
		$first = trim( (string) ( $post['new_first_name'] ?? '' ) );
		$last  = trim( (string) ( $post['new_last_name'] ?? '' ) );
		if ( ! $to_id && ( '' !== $first || '' !== $last ) ) {
			self::require_cap( 'apse_add_guest', (int) $actor['id'] );
			$to_id = Plugin::people()->create( array( 'type' => MemberType::GUEST, 'host_person_id' => (int) $actor['id'], 'first_name' => $first, 'last_name' => $last, 'phone' => (string) ( $post['new_phone'] ?? '' ) ) );
		}
		if ( ! $to_id ) {
			throw new \InvalidArgumentException( 'Scegli a chi intestare la prenotazione, oppure indica nome e cognome di un nuovo ospite.' );
		}
		self::require_cap( 'apse_book_for', $to_id );
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

	/**
	 * Avvia il pagamento online delle voci scelte (dovute da lui o dai suoi ospiti).
	 * @return string indirizzo della pagina di pagamento del gateway
	 */
	public static function do_pay( array $post ): string {
		$actor = self::actor();
		self::require_cap( 'apse_view_payments', (int) $actor['id'] );
		$back = remove_query_arg( array( 'apsf_ok', 'apsf_err', 'apse_pay', 'apse_ret', 'token', 'PayerID' ), self::back_url( $post ) );
		return Plugin::payments()->create_checkout( $actor, get_current_user_id(), (array) ( $post['items'] ?? array() ), $back );
	}

	/** Spesa registrata dal tesoriere (con scontrino e fatture allegati). Non vede né modifica altro della prima nota. */
	public static function do_expense( array $post ): string {
		self::require_cap( 'apse_add_expense', 0 );
		$date = (string) ( $post['date'] ?? '' );
		if ( $date > current_time( 'Y-m-d' ) ) {
			throw new \InvalidArgumentException( 'La data della spesa non può essere nel futuro.' );
		}
		$docs = Attachments::prepare( Attachments::from_request() ); // controllati prima: se un file non va, non si registra nulla
		$tx   = Plugin::ledger()->record_expense(
			array(
				'date'         => $date,
				'account_id'   => (int) ( $post['account_id'] ?? 0 ),
				'method'       => (string) ( $post['method'] ?? '' ),
				'category_id'  => (int) ( $post['category_id'] ?? 0 ),
				'amount_cents' => Money::parse( $post['amount'] ?? '' ) ?? 0,
				'activity_id'  => (int) ( $post['activity_id'] ?? 0 ),
				'description'  => $post['description'] ?? '',
				'document_ref' => $post['document_ref'] ?? '',
			)
		);
		$ids = Attachments::add( $tx, $docs );
		return 'Spesa registrata' . ( $ids ? ' con ' . count( $ids ) . ( 1 === count( $ids ) ? ' documento.' : ' documenti.' ) : '.' );
	}

	/** Altri documenti su una spesa registrata dallo stesso utente. */
	public static function do_expense_docs( array $post ): string {
		self::require_cap( 'apse_add_expense', 0 );
		$tx   = (int) ( $post['transaction_id'] ?? 0 );
		$mine = Plugin::ledger()->expenses_by_user( get_current_user_id(), 200 );
		if ( ! in_array( $tx, array_map( 'intval', array_column( $mine, 'id' ) ), true ) ) {
			throw new \InvalidArgumentException( 'Puoi aggiungere documenti solo alle spese che hai registrato tu.' );
		}
		$ids = Attachments::add( $tx, Attachments::prepare( Attachments::from_request(), $tx ) );
		if ( ! $ids ) {
			throw new \InvalidArgumentException( 'Scegli almeno un file.' );
		}
		return count( $ids ) . ( 1 === count( $ids ) ? ' documento aggiunto.' : ' documenti aggiunti.' );
	}

	/** Messaggio dopo una registrazione di ingresso: chi, e se c'è un contributo ancora da versare. */
	private static function checkin_message( array $r, int $session_id, int $person_id ): string {
		$name = '';
		$due  = '';
		foreach ( Plugin::activities()->bookings_for_session( $session_id ) as $b ) {
			if ( (int) $b['person_id'] === $person_id ) {
				$name = trim( $b['first_name'] . ' ' . $b['last_name'] );
				if ( $b['active'] && (int) $b['remaining'] > 0 ) {
					$due = ' — contributo da versare ' . Money::format( (int) $b['remaining'] );
				}
			}
		}
		switch ( $r['status'] ) {
			case 'recorded':
				return 'Ingresso registrato: ' . $name . $due . '.';
			case 'already':
				return 'Ingresso già registrato alle ' . mysql2date( 'H:i', $r['at'] ) . ': ' . $name . '.';
			case 'undone':
				return 'Registrazione annullata: ' . $name . '.';
		}
		return 'Nessun ingresso da annullare per ' . $name . '.';
	}

	/** Registra (o annulla) l'ingresso di una persona prenotata: solo per chi gestisce l'evento (referente, gestori indicati, amministratori). */
	public static function do_checkin( array $post ): string {
		$sid     = (int) ( $post['session_id'] ?? 0 );
		$pid     = (int) ( $post['person_id'] ?? 0 );
		$session = Plugin::activities()->session( $sid );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Data non trovata.' );
		}
		self::require_cap( 'apse_manage_event', (int) $session['activity_id'] );
		$r = Plugin::activities()->check_in( $sid, $pid, ! empty( $post['undo'] ), current_user_can( Plugin::CAP ) );
		return self::checkin_message( $r, $sid, $pid );
	}

	/** Ingresso sul posto: chi non ha prenotato viene prenotato (se c'è posto), paga il biglietto in cassa contanti e entra. Solo con l'incasso abilitato. */
	public static function do_door( array $post ): string {
		$sid     = (int) ( $post['session_id'] ?? 0 );
		$session = Plugin::activities()->session( $sid );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Data non trovata.' );
		}
		$aid = (int) $session['activity_id'];
		self::require_cap( 'apse_door_cash', $aid );
		if ( $session['session_date'] !== current_time( 'Y-m-d' ) && ! current_user_can( Plugin::CAP ) ) {
			throw new \InvalidArgumentException( 'L\'ingresso sul posto si registra nel giorno dell\'evento.' );
		}
		$account = 0;
		foreach ( Plugin::ledger()->accounts() as $acc ) {
			if ( 'cash' === $acc['type'] ) {
				$account = (int) $acc['id'];
				break;
			}
		}
		$pay = ! empty( $post['pay'] );
		if ( $pay && ! $account ) {
			throw new \InvalidArgumentException( 'Manca un conto di tipo "Cassa contanti": chiedi alla segreteria di crearlo.' );
		}
		return \ApSemplice\DoorSales::sell(
			array(
				'session_id' => $sid, 'activity_id' => $aid, 'person_id' => (int) ( $post['person_id'] ?? 0 ),
				'new_first_name' => (string) ( $post['new_first_name'] ?? '' ), 'new_last_name' => (string) ( $post['new_last_name'] ?? '' ), 'new_phone' => (string) ( $post['new_phone'] ?? '' ),
				'host_person_id' => (int) ( $post['host_person_id'] ?? 0 ), 'pay' => $pay, 'account_id' => $account, 'checkin' => true,
			),
			true
		);
	}

	/** Ingresso da QR scansionato: accetta l'indirizzo letto dal QR (o il solo codice). */
	public static function do_checkin_scan( array $post ): string {
		$raw = trim( (string) ( $post['ticket'] ?? '' ) );
		if ( false !== strpos( $raw, 'apse_ticket=' ) ) {
			parse_str( (string) wp_parse_url( $raw, PHP_URL_QUERY ), $q );
			$raw = (string) ( $q['apse_ticket'] ?? '' );
		}
		$t = CardToken::ticket_parse( $raw );
		if ( ! $t || ! CardToken::ticket_valid( $t[0], $t[1], $t[2], Settings::card_secret() ) ) {
			throw new \InvalidArgumentException( 'QR non valido: non è un biglietto di questo sito.' );
		}
		$session = Plugin::activities()->session( $t[0] );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Prenotazione non trovata.' );
		}
		self::require_cap( 'apse_manage_event', (int) $session['activity_id'] );
		return self::checkin_message( Plugin::activities()->check_in( $t[0], $t[1], false, current_user_can( Plugin::CAP ) ), $t[0], $t[1] );
	}

	/** Avviso agli iscritti di un'attività: solo chi la tiene o chi gestisce l'evento (e gli amministratori). */
	public static function do_notice( array $post ): string {
		$aid = (int) ( $post['activity_id'] ?? 0 );
		if ( ! \ApSemplice\Notices::can_send( $aid ) ) {
			throw new \InvalidArgumentException( 'Non hai il permesso di inviare avvisi per questa attività.' );
		}
		$r = \ApSemplice\Notices::send( $aid, ! empty( $post['session_id'] ) ? (int) $post['session_id'] : null, (string) ( $post['subject'] ?? '' ), (string) ( $post['body'] ?? '' ) );
		return 'Avviso inviato a ' . $r['recipients'] . ( 1 === $r['recipients'] ? ' persona' : ' persone' ) . ( $r['emailed'] < $r['recipients'] ? ' (' . ( $r['recipients'] - $r['emailed'] ) . ' email non partite)' : '' ) . '.';
	}

	public static function do_add_guest( array $post ): string {
		$actor = self::actor();
		self::require_cap( 'apse_add_guest', (int) $actor['id'] );
		$phone = (string) ( $post['phone'] ?? '' );
		// Nessun limite automatico: si evita solo il doppione evidente tra i propri ospiti e chi è già socio (il resto lo segnalano gli elenchi a chi gestisce).
		foreach ( Plugin::people()->find_by_phone( $phone ) as $h ) {
			if ( MemberType::is_member( $h['type'] ) ) {
				throw new \InvalidArgumentException( 'Questo cellulare è già di un socio: va prenotato come socio, non come tuo ospite.' );
			}
			if ( (int) $h['host_person_id'] === (int) $actor['id'] ) {
				throw new \InvalidArgumentException( 'Hai già questo ospite tra i tuoi.' );
			}
		}
		foreach ( Plugin::people()->guests_of( (int) $actor['id'] ) as $g ) {
			if ( \ApSemplice\Text::normalize( $g['first_name'] . $g['last_name'] ) === \ApSemplice\Text::normalize( ( $post['first_name'] ?? '' ) . ( $post['last_name'] ?? '' ) ) ) {
				throw new \InvalidArgumentException( 'Hai già questo ospite tra i tuoi.' );
			}
		}
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
		self::require_cap( 'apse_edit_own_profile', (int) $actor['id'] );
		Plugin::people()->update( (int) $actor['id'], array( 'phone' => $post['phone'] ?? '', 'tax_code' => $post['tax_code'] ?? '' ) );
		return 'Profilo aggiornato.';
	}
}
