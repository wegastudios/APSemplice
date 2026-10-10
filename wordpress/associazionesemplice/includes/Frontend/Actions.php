<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Access;
use AssociazioneSemplice\Attachments;
use AssociazioneSemplice\Bank;
use AssociazioneSemplice\CardToken;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Azioni dei soci dal sito (prenota, annulla, aggiungi un ospite, aggiorna il profilo).
 * La logica sta nei metodi `do_*` (lanciano \InvalidArgumentException con un messaggio leggibile);
 * il resto è solo il collegamento con admin-post.php, nonce e ritorno alla pagina di partenza.
 * Ogni azione controlla i permessi con le capability meta di {@see Access}: quindi vale anche la licenza.
 */
final class Actions {

	const MAP = array(
		'asem_front_book'           => 'do_book',
		'asem_front_accept_rules'   => 'do_accept_rules',
		'asem_front_waitlist_join'  => 'do_waitlist_join',
		'asem_front_waitlist_leave' => 'do_waitlist_leave',
		'asem_front_cancel_booking' => 'do_cancel_booking',
		'asem_front_transfer_booking' => 'do_transfer_booking',
		'asem_front_add_guest'      => 'do_add_guest',
		'asem_front_profile'        => 'do_profile',
		'asem_front_expense'        => 'do_expense',
		'asem_front_expense_docs'   => 'do_expense_docs',
		'asem_front_checkin'        => 'do_checkin',
		'asem_front_checkin_scan'   => 'do_checkin_scan',
		'asem_front_door'           => 'do_door',
		'asem_front_collect'        => 'do_collect',
		'asem_front_new_member'     => 'do_new_member',
		'asem_front_group'          => 'do_group_collect',
		'asem_front_door_group'     => 'do_door_group',
		'asem_front_notice'         => 'do_notice',
		'asem_front_bank_email'     => 'do_bank_email',
	);

	public static function register(): void {
		// Pagamento online: la risposta è un indirizzo esterno (pagina del gateway), non un messaggio.
		add_action(
			'admin_post_asem_front_pay',
			function () {
				check_admin_referer( 'asem_front_pay' );
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
			'admin_post_nopriv_asem_front_pay',
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
		wp_safe_redirect( \AssociazioneSemplice\Flash::url( $url, 'asemf', $ok, $err ) );
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

	/** Le azioni delle funzioni avanzate si rifiutano se la funzione non c'è in questa edizione. */
	private static function require_feature( string $feature ): void {
		if ( ! \AssociazioneSemplice\Edition::has( $feature ) ) {
			throw new \InvalidArgumentException( \AssociazioneSemplice\Edition::missing_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
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
		self::assert_profile( $actor );
		self::require_cap( 'asem_book_for', $person_id );
		$block = \AssociazioneSemplice\Regulation::booking_block( $actor );
		if ( '' !== $block ) {
			throw new \InvalidArgumentException( $block ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
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

	/** Mette in lista d'attesa sé stessi o un proprio ospite per una data senza più posti. */
	public static function do_waitlist_join( array $post ): string {
		$actor     = self::actor();
		$person_id = (int) ( $post['person_id'] ?? $actor['id'] );
		self::assert_profile( $actor );
		self::require_cap( 'asem_book_for', $person_id );
		$block = \AssociazioneSemplice\Regulation::booking_block( $actor );
		if ( '' !== $block ) {
			throw new \InvalidArgumentException( $block ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( ! Plugin::people()->is_active_member( (int) $actor['id'] ) ) {
			throw new \InvalidArgumentException( 'La tua tessera non è valida: rinnovala per prenotare.' );
		}
		$sid = (int) ( $post['session_id'] ?? 0 );
		\AssociazioneSemplice\Waitlist::join( $sid, $person_id, (int) $actor['id'] );
		return 'Sei in lista d\'attesa (posizione ' . (int) \AssociazioneSemplice\Waitlist::position( $sid, $person_id ) . '): se si libera un posto vieni prenotato automaticamente e ricevi un\'email.';
	}

	public static function do_waitlist_leave( array $post ): string {
		$actor     = self::actor();
		$person_id = (int) ( $post['person_id'] ?? $actor['id'] );
		self::require_cap( 'asem_book_for', $person_id );
		\AssociazioneSemplice\Waitlist::leave( (int) ( $post['session_id'] ?? 0 ), $person_id );
		return 'Hai lasciato la lista d\'attesa.';
	}

	/** Il socio accetta il regolamento in vigore. */
	public static function do_accept_rules( array $post ): string {
		$actor = self::actor();
		if ( ! \AssociazioneSemplice\Regulation::enabled() ) {
			throw new \InvalidArgumentException( 'Non c\'è nessun regolamento da accettare.' );
		}
		if ( empty( $post['rules_ok'] ) ) {
			throw new \InvalidArgumentException( 'Per continuare spunta la casella di accettazione.' );
		}
		\AssociazioneSemplice\Regulation::accept( (int) $actor['id'], 'web' );
		return 'Grazie, il ' . mb_strtolower( \AssociazioneSemplice\Regulation::title(), 'UTF-8' ) . ' è stato accettato.';
	}

	/** Annulla una prenotazione se la regola lo consente: gratis sempre; a pagamento solo se l'evento è cancellabile e nei termini. */
	public static function do_cancel_booking( array $post ): string {
		$actor     = self::actor();
		$person_id = (int) ( $post['person_id'] ?? $actor['id'] );
		self::require_cap( 'asem_book_for', $person_id );
		$session_id = (int) ( $post['session_id'] ?? 0 );
		if ( ! Plugin::activities()->session( $session_id ) ) {
			throw new \InvalidArgumentException( 'Evento non trovato.' );
		}
		$eval = Plugin::activities()->cancellation_for( $session_id, $person_id );
		if ( ! $eval['allowed'] ) {
			throw new \InvalidArgumentException( $eval['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
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
		self::require_cap( 'asem_book_for', $from_id );
		if ( ! Plugin::people()->is_active_member( (int) $actor['id'] ) ) {
			throw new \InvalidArgumentException( 'La tua tessera non è valida: rinnovala per gestire le prenotazioni.' );
		}
		$block = \AssociazioneSemplice\Regulation::booking_block( $actor );
		if ( '' !== $block ) {
			throw new \InvalidArgumentException( $block ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$to_id = (int) ( $post['to_person_id'] ?? 0 );
		$first = trim( (string) ( $post['new_first_name'] ?? '' ) );
		$last  = trim( (string) ( $post['new_last_name'] ?? '' ) );
		if ( ! $to_id && ( '' !== $first || '' !== $last ) ) {
			self::require_cap( 'asem_add_guest', (int) $actor['id'] );
			$to_id = Plugin::people()->create( array( 'type' => MemberType::GUEST, 'host_person_id' => (int) $actor['id'], 'first_name' => $first, 'last_name' => $last, 'phone' => (string) ( $post['new_phone'] ?? '' ) ) );
		}
		if ( ! $to_id ) {
			throw new \InvalidArgumentException( 'Scegli a chi intestare la prenotazione, oppure indica nome e cognome di un nuovo ospite.' );
		}
		self::require_cap( 'asem_book_for', $to_id );
		Plugin::activities()->transfer_booking( $session_id, $from_id, $to_id, true );
		$b   = Plugin::activities()->bookings_for_session( $session_id );
		$msg = 'Nominativo cambiato.';
		foreach ( $b as $row ) {
			if ( (int) $row['person_id'] === $to_id && $row['remaining'] > 0 ) {
				$msg .= ' Da integrare: ' . \AssociazioneSemplice\Money::format( (int) $row['remaining'] ) . ' (si paga in sede).';
			}
		}
		return $msg;
	}

	/**
	 * Avvia il pagamento online delle voci scelte (dovute da lui o dai suoi ospiti).
	 * @return string indirizzo della pagina di pagamento del gateway
	 */
	/** Chi si è attivato dal sito deve prima completare indirizzo e codice fiscale (vedi Limits «profile_gate»). */
	private static function assert_profile( array $actor ): void {
		if ( Plugin::people()->profile_blocks( $actor ) ) {
			throw new \InvalidArgumentException( 'Prima di prenotare o pagare completa i tuoi dati (indirizzo e codice fiscale) nel riquadro «Il mio profilo».' );
		}
	}

	public static function do_pay( array $post ): string {
		self::require_feature( 'payments' );
		$actor = self::actor();
		self::assert_profile( $actor );
		self::require_cap( 'asem_view_payments', (int) $actor['id'] );
		$back = remove_query_arg( array( 'asemf_ok', 'asemf_err', 'asemf_sig', 'asem_pay', 'asem_ret', 'token', 'PayerID' ), self::back_url( $post ) );
		return Plugin::payments()->create_checkout( $actor, get_current_user_id(), (array) ( $post['items'] ?? array() ), $back, sanitize_key( (string) ( $post['provider'] ?? '' ) ) );
	}

	/** Il socio si fa mandare per email le coordinate del bonifico (con le voci da pagare e la causale): sempre e solo al suo indirizzo. */
	public static function do_bank_email( array $post ): string {
		$actor = self::actor();
		self::require_cap( 'asem_view_payments', (int) $actor['id'] );
		$dues = Plugin::payments()->dues_for( $actor );
		$keys = array_map( 'strval', (array) ( $post['items'] ?? array() ) );
		if ( $keys ) { // solo le voci scelte (se non ne sceglie nessuna, tutte quelle dovute)
			$dues = array_intersect_key( $dues, array_flip( $keys ) );
		}
		Bank::send_to_member( $actor, array_values( $dues ), get_current_user_id() );
		return 'Ti abbiamo inviato le coordinate per il bonifico: controlla la tua email.';
	}

	/** Spesa registrata dal tesoriere (con scontrino e fatture allegati). Non vede né modifica altro della prima nota. */
	public static function do_expense( array $post ): string {
		self::require_cap( 'asem_add_expense', 0 );
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
		self::require_cap( 'asem_add_expense', 0 );
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

	/** Iscrizione di un nuovo socio ordinario dall'area riservata (tesoriere): bastano nome e cognome, il resto si completa dopo. */
	public static function do_new_member( array $post ): string {
		self::require_cap( 'asem_register_member', 0 );
		$first = trim( sanitize_text_field( (string) ( $post['first_name'] ?? '' ) ) );
		$last  = trim( sanitize_text_field( (string) ( $post['last_name'] ?? '' ) ) );
		if ( '' === $first || '' === $last ) {
			throw new \InvalidArgumentException( 'Indica nome e cognome.' );
		}
		$email = trim( (string) ( $post['email'] ?? '' ) );
		if ( '' !== $email && ! is_email( $email ) ) {
			throw new \InvalidArgumentException( 'L\'email non è valida.' );
		}
		$level = \AssociazioneSemplice\Levels::get( (int) ( $post['level_id'] ?? 0 ) );
		if ( ! \AssociazioneSemplice\Edition::has( 'levels' ) ) { // una quota sola: il tipo di socio non si sceglie
			$only  = \AssociazioneSemplice\Levels::choices( MemberType::ORDINARY );
			$level = $only ? $only[0] : null;
		}
		if ( ! $level || empty( $level['active'] ) || MemberType::ORDINARY !== $level['base_type'] ) {
			throw new \InvalidArgumentException( 'Scegli il tipo di socio.' );
		}
		$id = Plugin::people()->create(
			array(
				'type' => MemberType::ORDINARY, 'level_id' => (int) $level['id'], 'first_name' => $first, 'last_name' => $last,
				'email' => $email, 'phone' => trim( (string) ( $post['phone'] ?? '' ) ),
			)
		);
		\AssociazioneSemplice\Audit::log( 'person.registered_front', 'person', (int) $id );
		$p = Plugin::people()->get( (int) $id );
		return 'Socio registrato: ' . Plugin::people()->full_name( $p ) . ( ! empty( $p['card_number'] ) ? ' (tessera n. ' . $p['card_number'] . ')' : '' ) . '. Ora puoi incassare la quota associativa.';
	}

	/** Incasso del tesoriere dall'area riservata. */
	public static function do_collect( array $post ): string {
		self::require_cap( 'asem_collect', 0 );
		$ledger = Plugin::ledger();
		$lines  = array();
		foreach ( (array) ( $post['lines'] ?? array() ) as $l ) {
			$what = (string) ( $l['what'] ?? '' );
			if ( '' === $what ) {
				continue;
			}
			$cents = Money::parse( $l['amount'] ?? '' ) ?? 0;
			if ( 'm' === $what ) {
				$lines[] = array( 'category_id' => $ledger->category_id_of_kind( 'membership' ), 'amount_cents' => $cents );
			} elseif ( 0 === strpos( $what, 's:' ) ) {
				$s = Plugin::activities()->session( (int) substr( $what, 2 ) );
				if ( ! $s ) {
					throw new \InvalidArgumentException( 'Data dell\'evento non trovata.' );
				}
				$lines[] = array( 'category_id' => $ledger->category_id_of_kind( 'activity_fee' ), 'amount_cents' => $cents, 'activity_id' => (int) $s['activity_id'], 'session_id' => (int) $s['id'] );
			} elseif ( 0 === strpos( $what, 'k:' ) ) {
				$lines[] = array(
					'category_id' => $ledger->category_id_of_kind( 'activity_fee' ), 'amount_cents' => $cents, 'activity_id' => (int) substr( $what, 2 ),
					'competence_month' => Settings::social_year()->clamp( substr( current_time( 'Y-m-d' ), 0, 7 ) ),
				);
			} elseif ( 0 === strpos( $what, 'c:' ) ) {
				$lines[] = array( 'category_id' => (int) substr( $what, 2 ), 'amount_cents' => $cents );
			}
		}
		if ( ! $lines ) {
			throw new \InvalidArgumentException( 'Scegli almeno una voce da incassare.' );
		}
		$pid = (int) ( $post['person_id'] ?? 0 );
		$acts = Plugin::activities();
		$ledger->in_batch(
			function () use ( $post, $pid, $lines, $acts, $ledger ) {
				$people   = Plugin::people();
				$who      = $people->get( $pid );
				$cat_memb = $ledger->category_id_of_kind( 'membership' );
				$renewing = false;
				foreach ( $lines as $l ) {
					$renewing = $renewing || (int) $l['category_id'] === $cat_memb;
				}
				foreach ( $lines as $l ) {
					// Un socio con la tessera non in regola prenota solo se la rinnova nello stesso incasso
					if ( ! empty( $l['session_id'] ) && $who && MemberType::is_member( $who['type'] ) && ! $renewing && ! $people->is_active_member( $pid ) ) {
						throw new \InvalidArgumentException( 'La tessera di ' . trim( $who['first_name'] . ' ' . $who['last_name'] ) . ' non è in regola: aggiungi la quota associativa nello stesso incasso.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
					}
				}
				foreach ( $lines as $l ) { // corsi: tessera in regola (o rinnovata qui), socio non sospeso, iscrizione se manca
					if ( ! empty( $l['competence_month'] ) && ! empty( $l['activity_id'] ) ) {
						$cname = $who ? trim( $who['first_name'] . ' ' . $who['last_name'] ) : '';
						if ( $who && MemberType::is_member( $who['type'] ) && ! MemberType::is_auto_renewed( $who['type'] ) && ! $renewing && ! $people->is_active_member( $pid ) ) {
							throw new \InvalidArgumentException( $cname . ': per un corso la tessera deve essere in regola (aggiungi la quota associativa nello stesso incasso).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
						}
						if ( $people->is_suspended( $pid ) ) {
							throw new \InvalidArgumentException( $cname . ' è sospeso (inattivo): va riattivato prima di iscriverlo a un corso.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
						}
						if ( ! in_array( (int) $l['activity_id'], $acts->active_activity_ids( $pid ), true ) ) {
							$acts->enroll( (int) $l['activity_id'], $pid, (string) $l['competence_month'] );
						}
					}
				}
				foreach ( $lines as $l ) {
					if ( ! empty( $l['session_id'] ) && ! $acts->has_active_booking( (int) $l['session_id'], $pid ) ) {
						$acts->book( (int) $l['session_id'], $pid ); // chi paga un evento senza prenotazione viene prenotato (se c'e posto)
					}
				}
				$ledger->record_receipt( array( 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) ( $post['account_id'] ?? 0 ), 'method' => '', 'person_id' => $pid, 'lines' => $lines ) );
			}
		);
		return 'Incasso registrato.';
	}

	/** Importo standard di una voce (quando il tesoriere lascia l'importo vuoto). */
	private static function standard_cents( string $kind, int $ref, ?array $person ): int {
		$type = $person ? (string) $person['type'] : MemberType::GUEST;
		if ( 'membership' === $kind ) {
			return \AssociazioneSemplice\Levels::fee_for( $person );
		}
		if ( 'event' === $kind ) {
			$s = Plugin::activities()->session( $ref );
			$a = $s ? Plugin::activities()->get( (int) $s['activity_id'] ) : null;
			if ( ! $a ) {
				return 0;
			}
			if ( $person && Plugin::activities()->has_active_booking( $ref, (int) $person['id'] ) ) {
				foreach ( Plugin::activities()->bookings_for_session( $ref ) as $row ) {
					if ( (int) $row['person_id'] === (int) $person['id'] ) {
						return (int) $row['remaining'];
					}
				}
			}
			return Plugin::activities()->fee_for( $a, $type );
		}
		$a = Plugin::activities()->get( $ref );
		return $a ? Plugin::activities()->fee_for( $a, $type ) : 0;
	}

	/** Cassa per più persone del tesoriere (stesse regole degli amministratori). */
	public static function do_group_collect( array $post ): string {
		self::require_feature( 'funds' );
		self::require_cap( 'asem_collect', 0 );
		$month  = Settings::social_year()->clamp( substr( current_time( 'Y-m-d' ), 0, 7 ) );
		$people = array();
		foreach ( (array) ( $post['rows'] ?? array() ) as $i => $r ) {
			$r    = (array) $r;
			$what = (string) ( $r['what'] ?? '' );
			if ( '' === $what ) {
				continue;
			}
			$pid   = (int) ( $r['person'] ?? 0 );
			$first = trim( (string) ( $r['first'] ?? '' ) );
			$last  = trim( (string) ( $r['last'] ?? '' ) );
			if ( ! $pid && '' === $first && '' === $last ) {
				throw new \InvalidArgumentException( 'Riga ' . ( (int) $i + 1 ) . ': scegli la persona oppure scrivi nome e cognome del nuovo ospite.' );
			}
			$person = $pid ? Plugin::people()->get( $pid ) : null;
			if ( 'm' === $what ) {
				$line = array( 'kind' => 'membership', 'amount' => (string) ( $r['amount'] ?? '' ) );
				$std  = self::standard_cents( 'membership', 0, $person );
			} elseif ( 0 === strpos( $what, 's:' ) ) {
				$sid  = (int) substr( $what, 2 );
				$s    = Plugin::activities()->session( $sid );
				$line = array( 'kind' => 'event', 'session_id' => $sid, 'activity_id' => $s ? (int) $s['activity_id'] : 0, 'amount' => (string) ( $r['amount'] ?? '' ) );
				$std  = self::standard_cents( 'event', $sid, $person );
			} elseif ( 0 === strpos( $what, 'k:' ) ) {
				$aid  = (int) substr( $what, 2 );
				$line = array( 'kind' => 'course', 'activity_id' => $aid, 'month' => $month, 'amount' => (string) ( $r['amount'] ?? '' ) );
				$std  = self::standard_cents( 'course', $aid, $person );
			} else {
				throw new \InvalidArgumentException( 'Voce non riconosciuta.' );
			}
			if ( '' === trim( $line['amount'] ) ) {
				$line['amount'] = Money::plain( $std );
			}
			$key = $pid ? 'p' . $pid : 'n' . $i;
			if ( ! isset( $people[ $key ] ) ) {
				$people[ $key ] = $pid ? array( 'id' => $pid, 'lines' => array() ) : array( 'new' => 1, 'first' => $first, 'last' => $last, 'phone' => (string) ( $r['phone'] ?? '' ), 'lines' => array() );
			}
			$people[ $key ]['lines'][] = $line;
		}
		$s = \AssociazioneSemplice\GroupCash::record(
			array( 'payer_id' => (int) ( $post['payer_id'] ?? 0 ), 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) ( $post['account_id'] ?? 0 ), 'people' => array_values( $people ) )
		);
		return 'Incasso registrato: ' . $s['lines'] . ( 1 === $s['lines'] ? ' voce' : ' voci' ) . ' per ' . $s['people'] . ( 1 === $s['people'] ? ' persona' : ' persone' ) . ', totale ' . Money::format( $s['cents'] ) . '.';
	}

	/** Cassa per più soci sul posto (staff): un socio paga il biglietto per sé e per altri soci, importi calcolati dal sito. */
	public static function do_door_group( array $post ): string {
		self::require_feature( 'door_sales' );
		$sid     = (int) ( $post['session_id'] ?? 0 );
		$session = Plugin::activities()->session( $sid );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Data non trovata.' );
		}
		$aid = (int) $session['activity_id'];
		self::require_cap( 'asem_door_cash', $aid );
		$people = array();
		foreach ( (array) ( $post['rows'] ?? array() ) as $r ) {
			$pid = (int) ( ( (array) $r )['person'] ?? 0 );
			if ( $pid && ! isset( $people[ $pid ] ) ) {
				$people[ $pid ] = array( 'id' => $pid, 'lines' => array( array( 'kind' => 'event', 'session_id' => $sid, 'activity_id' => $aid, 'amount' => '' ) ) );
			}
		}
		if ( ! $people ) {
			throw new \InvalidArgumentException( 'Scegli almeno un socio.' );
		}
		$s = \AssociazioneSemplice\GroupCash::record(
			array( 'payer_id' => (int) ( $post['payer_id'] ?? 0 ), 'account_id' => (int) ( $post['account_id'] ?? 0 ), 'people' => array_values( $people ) ),
			array( 'staff' => true, 'activity_ids' => array( $aid ) )
		);
		foreach ( array_keys( $people ) as $pid ) { // chi paga all'ingresso entra: ingresso registrato per tutti
			try {
				Plugin::activities()->check_in( $sid, (int) $pid, false, current_user_can( Plugin::CAP_OPS ) );
			} catch ( \InvalidArgumentException $e ) {
				unset( $e );
			}
		}
		return 'Incasso registrato per ' . $s['people'] . ( 1 === $s['people'] ? ' socio' : ' soci' ) . ': ' . Money::format( $s['cents'] ) . ', ingressi registrati.';
	}

	/** Registra (o annulla) l'ingresso di una persona prenotata: solo per chi gestisce l'evento (referente, gestori indicati, amministratori). */
	public static function do_checkin( array $post ): string {
		$sid     = (int) ( $post['session_id'] ?? 0 );
		$pid     = (int) ( $post['person_id'] ?? 0 );
		$session = Plugin::activities()->session( $sid );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Data non trovata.' );
		}
		self::require_cap( 'asem_manage_event', (int) $session['activity_id'] );
		$r = Plugin::activities()->check_in( $sid, $pid, ! empty( $post['undo'] ), current_user_can( Plugin::CAP_OPS ) );
		return self::checkin_message( $r, $sid, $pid );
	}

	/** Ingresso sul posto: chi non ha prenotato viene prenotato (se c'è posto), paga il biglietto in contanti o con il POS e entra. Solo soci e solo con l'incasso abilitato. */
	public static function do_door( array $post ): string {
		self::require_feature( 'door_sales' );
		$sid     = (int) ( $post['session_id'] ?? 0 );
		$session = Plugin::activities()->session( $sid );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Data non trovata.' );
		}
		$aid = (int) $session['activity_id'];
		self::require_cap( 'asem_door_cash', $aid );
		if ( $session['session_date'] !== current_time( 'Y-m-d' ) && ! current_user_can( Plugin::CAP_OPS ) ) {
			throw new \InvalidArgumentException( 'L\'ingresso sul posto si registra nel giorno dell\'evento.' );
		}
		$account = (int) ( $post['account_id'] ?? 0 );
		foreach ( $account ? array() : Plugin::ledger()->accounts() as $acc ) {
			if ( 'cash' === $acc['type'] ) {
				$account = (int) $acc['id'];
				break;
			}
		}
		$pay = ! empty( $post['pay'] );
		if ( $pay && ! $account ) {
			throw new \InvalidArgumentException( 'Manca un conto di tipo "Cassa contanti": chiedi alla segreteria di crearlo.' );
		}
		return \AssociazioneSemplice\DoorSales::sell(
			array(
				'session_id' => $sid, 'activity_id' => $aid, 'person_id' => (int) ( $post['person_id'] ?? 0 ),
				'pay' => $pay, 'account_id' => $account, 'checkin' => true,
			),
			true
		);
	}

	/** Ingresso da QR scansionato: accetta l'indirizzo letto dal QR (o il solo codice). */
	public static function do_checkin_scan( array $post ): string {
		$raw = trim( (string) ( $post['ticket'] ?? '' ) );
		if ( false !== strpos( $raw, 'asem_ticket=' ) ) {
			parse_str( (string) wp_parse_url( $raw, PHP_URL_QUERY ), $q );
			$raw = (string) ( $q['asem_ticket'] ?? '' );
		}
		$t = CardToken::ticket_parse( $raw );
		if ( ! $t || ! CardToken::ticket_valid( $t[0], $t[1], $t[2], Settings::card_secret() ) ) {
			throw new \InvalidArgumentException( 'QR non valido: non è un biglietto di questo sito.' );
		}
		$session = Plugin::activities()->session( $t[0] );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Prenotazione non trovata.' );
		}
		self::require_cap( 'asem_manage_event', (int) $session['activity_id'] );
		return self::checkin_message( Plugin::activities()->check_in( $t[0], $t[1], false, current_user_can( Plugin::CAP_OPS ) ), $t[0], $t[1] );
	}

	/** Avviso agli iscritti di un'attività: solo chi la tiene o chi gestisce l'evento (e gli amministratori). */
	public static function do_notice( array $post ): string {
		$aid = (int) ( $post['activity_id'] ?? 0 );
		if ( ! \AssociazioneSemplice\Notices::can_send( $aid ) ) {
			throw new \InvalidArgumentException( 'Non hai il permesso di inviare avvisi per questa attività.' );
		}
		$r = \AssociazioneSemplice\Notices::send( $aid, ! empty( $post['session_id'] ) ? (int) $post['session_id'] : null, (string) ( $post['subject'] ?? '' ), (string) ( $post['body'] ?? '' ) );
		return 'Avviso inviato a ' . $r['recipients'] . ( 1 === $r['recipients'] ? ' persona' : ' persone' ) . ( $r['emailed'] < $r['recipients'] ? ' (' . ( $r['recipients'] - $r['emailed'] ) . ' email non inviate)' : '' ) . '.';
	}

	public static function do_add_guest( array $post ): string {
		if ( ! Settings::guests_enabled() ) {
			throw new \InvalidArgumentException( 'L\'ente non accetta ospiti.' );
		}
		$actor = self::actor();
		self::require_cap( 'asem_add_guest', (int) $actor['id'] );
		$phone = (string) ( $post['phone'] ?? '' );
		// Nessun limite automatico: si evita solo il doppione evidente tra i propri ospiti e chi è già socio (il resto lo segnalano gli elenchi a chi gestisce).
		foreach ( Plugin::people()->find_by_phone( $phone ) as $h ) {
			if ( MemberType::is_member( $h['type'] ) ) {
				throw new \InvalidArgumentException( 'Non è possibile aggiungere questo numero come tuo ospite: scrivi alla segreteria.' ); // non si dice perché: nessuno può scoprire chi è socio provando dei numeri
			}
			if ( (int) $h['host_person_id'] === (int) $actor['id'] ) {
				throw new \InvalidArgumentException( 'Hai già questo ospite tra i tuoi.' );
			}
		}
		foreach ( Plugin::people()->guests_of( (int) $actor['id'] ) as $g ) {
			if ( \AssociazioneSemplice\Text::normalize( $g['first_name'] . $g['last_name'] ) === \AssociazioneSemplice\Text::normalize( ( $post['first_name'] ?? '' ) . ( $post['last_name'] ?? '' ) ) ) {
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
		self::require_cap( 'asem_edit_own_profile', (int) $actor['id'] );
		$people = Plugin::people();
		$in     = array(
			'phone' => $post['phone'] ?? '', 'tax_code' => $post['tax_code'] ?? '', 'address' => $post['address'] ?? '', 'zip' => $post['zip'] ?? '',
			'city'  => $post['city'] ?? '', 'province' => $post['province'] ?? '',
		);
		$in['tax_code'] = \AssociazioneSemplice\TaxCode::normalize( (string) $in['tax_code'] );
		if ( '' !== $in['tax_code'] && ! \AssociazioneSemplice\TaxCode::is_valid( $in['tax_code'] ) ) {
			throw new \InvalidArgumentException( 'Il codice fiscale non è valido: controllalo (16 caratteri).' );
		}
		if ( '' !== (string) $in['zip'] && ! preg_match( '/^[0-9A-Za-z\- ]{3,12}$/', (string) $in['zip'] ) ) {
			throw new \InvalidArgumentException( 'Il CAP non è valido.' );
		}
		$people->update( (int) $actor['id'], $in );
		$fresh = $people->get( (int) $actor['id'] );
		if ( ! empty( $actor['profile_due'] ) && $fresh && $people->profile_complete( $fresh ) ) {
			$people->set_profile_due( (int) $actor['id'], false ); // dati completi: nessun blocco
			return 'Profilo completato: ora puoi prenotare e pagare.';
		}
		return 'Profilo aggiornato.';
	}
}
