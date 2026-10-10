<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Comunicazioni a gruppi: un messaggio email a un gruppo di persone (soci in regola, in scadenza, iscritti a un corso, prenotati a un evento…).
 * Ogni persona riceve la propria email (nessun indirizzo visibile agli altri). L'invio avviene a gruppi di 25 e continua da solo in
 * background (WP-Cron) se i destinatari sono molti; lo storico mostra chi l'ha ricevuta e chi no. Chi non ha email (un ospite) riceve il messaggio
 * tramite il socio che lo ospita.
 */
final class Broadcasts {

	const HOOK       = 'asem_broadcast_batch';
	const BATCH      = 25;
	const MAX_SUBJ   = 120;
	const MAX_BODY   = 5000;
	const MAX_PER_DAY = 20;

	private static function db(): \wpdb {
		return Db::db();
	}

	// ---------- Gruppi di destinatari ----------

	/** Gruppi fissi: chiave => etichetta. */
	public static function audiences(): array {
		return array(
			'members_active'   => 'Soci con la tessera in regola',
			'members_all'      => 'Tutti i soci',
			'members_expiring' => 'Soci con la tessera in scadenza (entro 30 giorni) o scaduta da poco',
			'members_expired'  => 'Soci con la tessera scaduta',
			'volunteers'       => 'Soci e volontari',
			'board'            => 'Consiglio direttivo',
			'guests'           => 'Ospiti',
			'norules'          => 'Soci che non hanno accettato il regolamento',
		);
	}

	/** Persone di un gruppo. $ref: id attività (audience "activity") o id data (audience "session"). @return array[] persone */
	public static function people_of( string $audience, int $ref = 0 ): array {
		$people = Plugin::people();
		$today  = current_time( 'Y-m-d' );
		$soon   = gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) );
		$ago    = gmdate( 'Y-m-d', strtotime( $today . ' -60 days' ) );
		$all    = $people->search();
		$out    = array();
		switch ( $audience ) {
			case 'members_all':
				foreach ( $all as $p ) {
					if ( MemberType::is_member( $p['type'] ) ) {
						$out[] = $p;
					}
				}
				break;
			case 'members_active':
				foreach ( $all as $p ) {
					if ( MemberType::is_member( $p['type'] ) && empty( $p['suspended_at'] ) && ( MemberType::is_auto_renewed( $p['type'] ) || ( ! empty( $p['active_until'] ) && $p['active_until'] >= $today ) ) ) {
						$out[] = $p;
					}
				}
				break;
			case 'members_expiring':
				foreach ( $all as $p ) {
					if ( MemberType::is_member( $p['type'] ) && ! MemberType::is_auto_renewed( $p['type'] ) && empty( $p['suspended_at'] ) && ! empty( $p['active_until'] ) && $p['active_until'] <= $soon && $p['active_until'] >= $ago ) {
						$out[] = $p;
					}
				}
				break;
			case 'members_expired':
				foreach ( $all as $p ) {
					if ( MemberType::is_member( $p['type'] ) && ! MemberType::is_auto_renewed( $p['type'] ) && empty( $p['suspended_at'] ) && ( empty( $p['active_until'] ) || $p['active_until'] < $today ) ) {
						$out[] = $p;
					}
				}
				break;
			case 'volunteers':
				foreach ( $all as $p ) {
					if ( MemberType::can_teach( $p['type'] ) ) {
						$out[] = $p;
					}
				}
				break;
			case 'board':
				foreach ( $people->board() as $p ) {
					$out[] = $p;
				}
				break;
			case 'guests':
				foreach ( $all as $p ) {
					if ( MemberType::GUEST === $p['type'] ) {
						$out[] = $p;
					}
				}
				break;
			case 'norules':
				foreach ( $all as $p ) {
					if ( Regulation::applies_to( $p ) && ! Regulation::accepted( $p ) ) {
						$out[] = $p;
					}
				}
				break;
			case 'activity':
				$a = Plugin::activities()->get( $ref );
				if ( $a && ActivityKind::uses_sessions( $a['kind'] ) ) {
					foreach ( Plugin::activities()->booked_people( $ref ) as $b ) {
						$p = $people->get( (int) $b['person_id'] );
						if ( $p ) {
							$out[] = $p;
						}
					}
				} elseif ( $a ) {
					foreach ( Plugin::activities()->status_for_activity( $ref ) as $s ) {
						if ( null === $s['enrollment']['end_month'] ) {
							$p = $people->get( (int) $s['enrollment']['person_id'] );
							if ( $p ) {
								$out[] = $p;
							}
						}
					}
				}
				break;
			case 'session':
				foreach ( Plugin::activities()->bookings_for_session( $ref ) as $b ) {
					if ( $b['active'] ) {
						$p = $people->get( (int) $b['person_id'] );
						if ( $p ) {
							$out[] = $p;
						}
					}
				}
				break;
			default:
				throw new \InvalidArgumentException( 'Gruppo di destinatari non valido.' );
		}
		return $out;
	}

	/**
	 * Destinatari con una email (la propria o, per gli ospiti senza email, quella del socio che li ospita), senza doppioni.
	 *
	 * @return array{list:array[],no_email:int} list: person_id, email, name
	 */
	public static function recipients( string $audience, int $ref = 0 ): array {
		$seen  = array();
		$list  = array();
		$no    = 0;
		foreach ( self::people_of( $audience, $ref ) as $p ) {
			$to = Reminders::recipient( $p );
			if ( ! $to ) {
				$no++;
				continue;
			}
			$key = Text::lower( $to['email'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$list[]       = array( 'person_id' => (int) $p['id'], 'email' => $to['email'], 'name' => $to['name'] );
		}
		return array( 'list' => $list, 'no_email' => $no );
	}

	// ---------- Invio ----------

	private static function text_for( string $body, string $name ): string {
		$assoc = (string) Settings::get( 'association_name' );
		return str_replace( array( '{nome}', '{associazione}' ), array( $name, $assoc ), $body )
			. "\n\n—\n" . ( '' !== $assoc ? $assoc . "\n" : '' ) . 'Area riservata: ' . Gatekeeper::area_url() . "\nComunicazione di servizio ai soci e ai partecipanti.";
	}

	/**
	 * Crea la comunicazione e manda il primo gruppo di email; il resto continua in background.
	 *
	 * @return int id della comunicazione
	 * @throws \InvalidArgumentException
	 */
	public static function create( string $subject, string $body, string $audience, int $ref = 0 ): int {
		if ( ! Edition::has( 'broadcasts' ) ) {
			throw new \InvalidArgumentException( Edition::missing_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$subject = trim( (string) preg_replace( '/\s+/', ' ', $subject ) ); // una sola riga: niente a capo nell'oggetto
		$body    = trim( str_replace( "\r\n", "\n", $body ) );
		if ( '' === $subject || '' === $body ) {
			throw new \InvalidArgumentException( 'Scrivi l\'oggetto e il testo del messaggio.' );
		}
		if ( mb_strlen( $subject ) > self::MAX_SUBJ || mb_strlen( $body ) > self::MAX_BODY ) {
			throw new \InvalidArgumentException( 'Messaggio troppo lungo: oggetto fino a ' . self::MAX_SUBJ . ' caratteri, testo fino a ' . self::MAX_BODY . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$db    = self::db();
		$today = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'broadcasts' ) . ' WHERE created_at >= %s', gmdate( 'Y-m-d H:i:s', strtotime( Db::now() . ' -1 day' ) ) ) );
		if ( $today >= Limits::get( 'broadcast_per_day' ) ) {
			throw new \InvalidArgumentException( 'Sono già state inviate ' . Limits::get( 'broadcast_per_day' ) . ' comunicazioni nelle ultime 24 ore: aspetta un po\'.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$r = self::recipients( $audience, $ref );
		if ( ! $r['list'] ) {
			throw new \InvalidArgumentException( 'Nessun destinatario con un indirizzo email in questo gruppo.' );
		}
		$db->insert(
			Db::t( 'broadcasts' ),
			array(
				'subject' => $subject, 'body' => $body, 'audience' => mb_substr( $audience, 0, 40 ), 'audience_ref' => $ref ?: null, 'status' => 'sending', 'total' => count( $r['list'] ),
				'sent' => 0, 'failed' => 0, 'created_by' => get_current_user_id() ?: null, 'created_at' => Db::now(),
			)
		);
		$id = (int) $db->insert_id;
		foreach ( $r['list'] as $x ) {
			$db->insert( Db::t( 'broadcast_rcpt' ), array( 'broadcast_id' => $id, 'person_id' => $x['person_id'], 'email' => mb_substr( $x['email'], 0, 190 ), 'name' => mb_substr( $x['name'], 0, 120 ), 'status' => 'queued' ) );
		}
		Audit::log( 'broadcast.created', 'broadcast', $id, array( 'audience' => $audience, 'recipients' => count( $r['list'] ) ) ); // senza il testo
		self::process( $id );
		return $id;
	}

	/** Manda il prossimo gruppo di email di una comunicazione. @return int rimaste da mandare */
	public static function process( int $id, ?int $batch = null ): int {
		$batch = $batch ?: Limits::get( 'broadcast_batch' );
		$db = self::db();
		$b  = self::get( $id );
		if ( ! $b || 'sending' !== $b['status'] || ! Edition::has( 'broadcasts' ) ) {
			return 0; // con la licenza non in regola l'invio resta fermo: riprende da solo quando torna in regola
		}
		$rows = $db->get_results( $db->prepare( 'SELECT * FROM ' . Db::t( 'broadcast_rcpt' ) . " WHERE broadcast_id = %d AND status = 'queued' ORDER BY id LIMIT %d", $id, $batch ), ARRAY_A ) ?: array();
		$assoc = (string) Settings::get( 'association_name' );
		foreach ( $rows as $r ) {
			// si prende in carico la riga: se un altro passaggio (cron e pagina insieme) sta mandando le stesse email, non si manda due volte
			if ( 1 !== (int) $db->update( Db::t( 'broadcast_rcpt' ), array( 'status' => 'sending' ), array( 'id' => (int) $r['id'], 'status' => 'queued' ) ) ) {
				continue;
			}
			$ok = Texts::mail( $r['email'], ( '' !== $assoc ? '[' . $assoc . '] ' : '' ) . $b['subject'], self::text_for( $b['body'], $r['name'] ) );
			$first = (string) strtok( (string) $r['name'], ' ' );
			Push::notify_email( (string) $r['email'], (string) $b['subject'], strtr( (string) $b['body'], array( '{nome}' => $first, '{associazione}' => $assoc ) ) ); // anche sul telefono, se ha attivato le notifiche
			$db->update( Db::t( 'broadcast_rcpt' ), array( 'status' => $ok ? 'sent' : 'failed', 'sent_at' => Db::now(), 'error' => $ok ? null : 'invio non riuscito' ), array( 'id' => (int) $r['id'] ) );
		}
		$left = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'broadcast_rcpt' ) . " WHERE broadcast_id = %d AND status = 'queued'", $id ) );
		$sent = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'broadcast_rcpt' ) . " WHERE broadcast_id = %d AND status = 'sent'", $id ) );
		$fail = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'broadcast_rcpt' ) . " WHERE broadcast_id = %d AND status = 'failed'", $id ) );
		$db->update( Db::t( 'broadcasts' ), array( 'sent' => $sent, 'failed' => $fail, 'status' => $left > 0 ? 'sending' : 'done', 'finished_at' => $left > 0 ? null : Db::now() ), array( 'id' => $id ) );
		if ( $left > 0 && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::HOOK );
		}
		return $left;
	}

	/** Chiamata da WP-Cron: avanza tutte le comunicazioni in corso. */
	public static function process_all(): void {
		if ( ! Edition::has( 'broadcasts' ) ) { // licenza scaduta: gli invii in coda restano fermi
			return;
		}
		$ids = self::db()->get_col( 'SELECT id FROM ' . Db::t( 'broadcasts' ) . " WHERE status = 'sending' ORDER BY id LIMIT 5" ) ?: array();
		foreach ( $ids as $id ) {
			self::process( (int) $id );
		}
	}

	/** Invia una prova al solo utente collegato (non viene registrata). */
	public static function send_test( string $subject, string $body ): bool {
		$u = wp_get_current_user();
		if ( ! $u || ! is_email( $u->user_email ) ) {
			throw new \InvalidArgumentException( 'L\'utente collegato non ha un indirizzo email.' );
		}
		return Texts::mail( $u->user_email, '[Prova] ' . trim( $subject ), self::text_for( trim( $body ), (string) ( $u->first_name ?: $u->display_name ) ) );
	}

	// ---------- Storico ----------

	public static function get( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'broadcasts' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	public static function recent( int $limit = 30 ): array {
		return self::db()->get_results( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'broadcasts' ) . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ) ?: array();
	}

	public static function recipients_of( int $id ): array {
		return self::db()->get_results( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'broadcast_rcpt' ) . ' WHERE broadcast_id = %d ORDER BY id', $id ), ARRAY_A ) ?: array();
	}

	/** Rimette in coda le email non partite di una comunicazione. @return int rimesse in coda */
	public static function retry_failed( int $id ): int {
		$n = (int) self::db()->query( self::db()->prepare( 'UPDATE ' . Db::t( 'broadcast_rcpt' ) . " SET status = 'queued', error = NULL WHERE broadcast_id = %d AND status = 'failed'", $id ) );
		if ( $n > 0 ) {
			self::db()->update( Db::t( 'broadcasts' ), array( 'status' => 'sending', 'finished_at' => null ), array( 'id' => $id ) );
			self::process( $id );
		}
		return $n;
	}

	public static function audience_label( array $b ): string {
		if ( 'activity' === $b['audience'] ) {
			$a = Plugin::activities()->get( (int) $b['audience_ref'] );
			return 'Attività: ' . ( $a ? $a['name'] : '—' );
		}
		if ( 'session' === $b['audience'] ) {
			$s = Plugin::activities()->session( (int) $b['audience_ref'] );
			$a = $s ? Plugin::activities()->get( (int) $s['activity_id'] ) : null;
			return 'Prenotati: ' . ( $a ? $a['name'] : '—' ) . ( $s ? ' · ' . ( new \DateTimeImmutable( $s['session_date'] ) )->format( 'd/m/Y' ) : '' );
		}
		return self::audiences()[ $b['audience'] ] ?? $b['audience'];
	}
}
