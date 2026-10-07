<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Eliminazione completa di un evento (o di un'attività), con la sorte delle somme incassate:
 *
 *  - «refund»: gli incassi restano in prima nota e per ciascuno si registra l'uscita di restituzione (stesso conto e stessa modalità);
 *  - «void»: gli incassi vengono annullati, come se non fossero mai avvenuti (restano consultabili tra gli annullamenti e nel registro azioni).
 *
 * In entrambi i casi si cancellano date, prenotazioni, iscrizioni, lista d'attesa, staff, presenze, avvisi, fondo di rimborso e collegamenti.
 * I movimenti che restano in prima nota perdono il collegamento con l'evento e riportano nella descrizione «[Evento eliminato: nome]».
 * Tutto avviene in un'unica transazione: o si fa tutto o non si cambia nulla.
 */
final class ActivityReset {

	const REFUND = 'refund';
	const VOID   = 'void';

	const ONLINE_METHODS = array( 'stripe', 'paypal', 'woocommerce', 'online' );

	private static function db(): \wpdb {
		return Db::db();
	}

	/** @return array<int,array> movimenti collegati all'attività (non annullati): incassi e spese */
	private static function movements( int $activity_id ): array {
		return self::db()->get_results(
			self::db()->prepare( 'SELECT * FROM ' . Db::t( 'transactions' ) . ' WHERE activity_id = %d AND voided_at IS NULL AND type IN ("income","expense") ORDER BY id', $activity_id ),
			ARRAY_A
		) ?: array();
	}

	/** Cosa verrebbe eliminato e cosa succederebbe ai soldi. @return array|null null se l'attività non esiste */
	public static function preview( int $activity_id ): ?array {
		$a = Plugin::activities()->get( $activity_id );
		if ( ! $a ) {
			return null;
		}
		$db = self::db();
		$t  = function ( string $name ) {
			return Db::t( $name );
		};
		$sessions = array_map( 'intval', $db->get_col( $db->prepare( 'SELECT id FROM ' . $t( 'sessions' ) . ' WHERE activity_id = %d', $activity_id ) ) );
		$in       = $sessions ? implode( ',', $sessions ) : '0';
		$out      = array(
			'activity'    => $a,
			'sessions'    => count( $sessions ),
			'bookings'    => (int) $db->get_var( 'SELECT COUNT(*) FROM ' . $t( 'bookings' ) . " WHERE session_id IN ($in) AND status = 'booked'" ),
			'enrollments' => (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . $t( 'enrollments' ) . ' WHERE activity_id = %d AND (end_month IS NULL OR end_month >= %s)', $activity_id, gmdate( 'Y-m' ) ) ),
			'staff'       => (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . $t( 'activity_staff' ) . ' WHERE activity_id = %d', $activity_id ) ),
			'notices'     => (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . $t( 'notices' ) . ' WHERE activity_id = %d', $activity_id ) ),
			'income'      => array( 'count' => 0, 'cents' => 0, 'online_cents' => 0, 'ids' => array(), 'receipts' => array() ),
			'income_open' => array( 'count' => 0, 'cents' => 0, 'online_cents' => 0 ),
			'expense'     => array( 'count' => 0, 'cents' => 0, 'ids' => array() ),
			'refund_exp'  => array( 'count' => 0, 'cents' => 0, 'ids' => array() ),
			'fund_cents'  => 0,
			'years'       => array(),
			'years_income' => array(),
			'years_income_open' => array(),
			'years_expense' => array(),
			'years_refund_exp' => array(),
			'recipients'  => count( self::recipients( $activity_id, $sessions ) ),
		);
		foreach ( self::movements( $activity_id ) as $m ) {
			$is_inc  = 'income' === $m['type'];
			$k       = $is_inc ? 'income' : ( self::is_refund_expense( $m ) ? 'refund_exp' : 'expense' );
			$settled = $is_inc && self::is_settled( $m ); // incasso già restituito con la cancellazione di una singola iscrizione
			$y       = (int) substr( (string) $m['tx_date'], 0, 4 );
			$out[ $k ]['count']++;
			$out[ $k ]['cents'] += (int) $m['amount_cents'];
			$out[ $k ]['ids'][]  = (int) $m['id'];
			$out['years'][ $y ]  = true;
			$out[ 'years_' . $k ][ $y ] = true;
			if ( $is_inc ) {
				$online = in_array( (string) $m['method'], self::ONLINE_METHODS, true ) ? (int) $m['amount_cents'] : 0;
				$out['income']['online_cents'] += $online;
				if ( ! empty( $m['receipt_id'] ) ) {
					$out['income']['receipts'][ (string) $m['receipt_id'] ] = true;
				}
				if ( ! $settled ) {
					$out['income_open']['count']++;
					$out['income_open']['cents']        += (int) $m['amount_cents'];
					$out['income_open']['online_cents'] += $online;
					$out['years_income_open'][ $y ]      = true;
				}
			}
		}
		$out['income']['receipts'] = array_keys( $out['income']['receipts'] );
		$fund = $db->get_var( $db->prepare( 'SELECT id FROM ' . $t( 'funds' ) . ' WHERE activity_id = %d', $activity_id ) );
		if ( $fund ) {
			$out['fund_cents'] = (int) $db->get_var( $db->prepare( 'SELECT COALESCE(SUM(cents),0) FROM ' . $t( 'fund_entries' ) . " WHERE fund_id = %d AND kind = 'share'", (int) $fund ) );
		}
		ksort( $out['years'] );
		foreach ( array( 'years', 'years_income', 'years_income_open', 'years_expense', 'years_refund_exp' ) as $yk ) {
			$out[ $yk ] = array_keys( $out[ $yk ] );
		}
		return $out;
	}

	/** Incasso già restituito (o annullato) con la cancellazione di una singola iscrizione. */
	private static function is_settled( array $m ): bool {
		return 0 === strpos( (string) $m['description'], '[Iscrizione cancellata]' );
	}

	/** Uscita di restituzione registrata dalla cancellazione di una singola iscrizione. */
	private static function is_refund_expense( array $m ): bool {
		return 'expense' === $m['type'] && false !== strpos( (string) $m['description'], 'Restituzione di un incasso' );
	}

	/** Motivi per cui non si può procedere (anni chiusi, pagamenti online in corso). @return string[] */
	public static function blockers( int $activity_id, string $mode, bool $void_costs = false ): array {
		$p = self::preview( $activity_id );
		if ( ! $p ) {
			return array( 'L\'attività non esiste.' );
		}
		$out   = array();
		if ( self::REFUND === $mode ) { // si restituiscono solo gli incassi non ancora restituiti; le restituzioni si registrano oggi
			$years = $p['years_income_open'];
			if ( $p['income_open']['count'] ) {
				$years[] = (int) current_time( 'Y' );
			}
		} else { // si annullano gli incassi e le restituzioni che li compensano; le altre spese solo se si sceglie di annullarle
			$years = array_merge( $p['years_income'], $p['years_refund_exp'] );
			if ( $void_costs ) {
				$years = array_merge( $years, $p['years_expense'] );
			}
		}
		if ( $years ) {
			foreach ( array_unique( $years ) as $y ) {
				try {
					FiscalYears::assert_open_for_date( (int) $y . '-06-15' );
				} catch ( \InvalidArgumentException $e ) {
					$out[] = $e->getMessage() . ' (Contiene movimenti di questo evento, o è l\'anno in cui si registrerebbe la restituzione.)';
				}
			}
		}
		$since = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		foreach ( self::db()->get_results( self::db()->prepare( 'SELECT id, items FROM ' . Db::t( 'payments' ) . " WHERE status IN ('created','pending') AND updated_at >= %s", $since ), ARRAY_A ) ?: array() as $pay ) {
			foreach ( (array) json_decode( (string) $pay['items'], true ) as $it ) {
				if ( (int) ( $it['activity_id'] ?? 0 ) === $activity_id ) {
					$out[] = 'C\'è un pagamento online in corso per questo evento: attendi che si concluda (o scada) prima di eliminarlo.';
					break 2;
				}
			}
		}
		return $out;
	}

	/** Persone con l'email a cui avvisare dell'annullamento (prenotate o iscritte). @return array<int,array> */
	private static function recipients( int $activity_id, array $session_ids ): array {
		$db   = self::db();
		$in   = $session_ids ? implode( ',', array_map( 'intval', $session_ids ) ) : '0';
		$ids  = array_map( 'intval', $db->get_col( 'SELECT DISTINCT person_id FROM ' . Db::t( 'bookings' ) . " WHERE session_id IN ($in) AND status = 'booked'" ) );
		$ids  = array_merge( $ids, array_map( 'intval', $db->get_col( $db->prepare( 'SELECT DISTINCT person_id FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND (end_month IS NULL OR end_month >= %s)', $activity_id, gmdate( 'Y-m' ) ) ) ) );
		$out  = array();
		foreach ( array_unique( $ids ) as $pid ) {
			$p = Plugin::people()->get( $pid );
			if ( $p && is_email( (string) $p['email'] ) ) {
				$out[ $pid ] = $p;
			}
		}
		return $out;
	}

	/** Categoria per le restituzioni: si crea la prima volta (è un costo dell'attività). */
	private static function refund_category(): int {
		$db  = self::db();
		$tbl = Db::t( 'categories' );
		$id  = (int) $db->get_var( $db->prepare( "SELECT id FROM $tbl WHERE name = %s AND kind = 'activity_cost' AND deleted_at IS NULL LIMIT 1", 'Restituzione quote' ) );
		if ( ! $id ) {
			$db->insert( $tbl, array( 'name' => 'Restituzione quote', 'kind' => 'activity_cost' ) );
			$id = (int) $db->insert_id;
		}
		return $id;
	}

	/**
	 * Elimina l'attività e tutto ciò che la riguarda.
	 *
	 * @param array $opts notify (avvisa chi è prenotato o iscritto), void_costs (solo con «void»: annulla anche le spese collegate)
	 * @return array riepilogo: sessions, bookings, income_count, income_cents, refunds, voided, expenses_voided, notified
	 * @throws \InvalidArgumentException
	 */
	public static function delete( int $activity_id, string $mode, array $opts = array() ): array {
		if ( ! in_array( $mode, array( self::REFUND, self::VOID ), true ) ) {
			throw new \InvalidArgumentException( 'Scegli cosa fare delle somme incassate.' );
		}
		$blockers = self::blockers( $activity_id, $mode, ! empty( $opts['void_costs'] ) );
		if ( $blockers ) {
			throw new \InvalidArgumentException( $blockers[0] );
		}
		$p    = self::preview( $activity_id );
		$a    = $p['activity'];
		$name = (string) $a['name'];
		$db   = self::db();
		$ledger = Plugin::ledger();
		$sessions = array_map( 'intval', $db->get_col( $db->prepare( 'SELECT id FROM ' . Db::t( 'sessions' ) . ' WHERE activity_id = %d', $activity_id ) ) );
		$mail_to  = ! empty( $opts['notify'] ) ? self::recipients( $activity_id, $sessions ) : array();
		$sum      = array( 'sessions' => count( $sessions ), 'bookings' => $p['bookings'], 'income_count' => self::REFUND === $mode ? $p['income_open']['count'] : $p['income']['count'], 'income_cents' => self::REFUND === $mode ? $p['income_open']['cents'] : $p['income']['cents'], 'refunds' => 0, 'voided' => 0, 'expenses_voided' => 0, 'notified' => 0, 'name' => $name );

		$ledger->in_batch(
			function () use ( $activity_id, $mode, $opts, $name, $sessions, $ledger, $db, &$sum ) {
				$today = current_time( 'Y-m-d' );
				$cat   = self::REFUND === $mode ? self::refund_category() : 0;
				foreach ( self::movements( $activity_id ) as $m ) {
					if ( 'income' === $m['type'] ) {
						if ( self::REFUND === $mode ) {
							if ( self::is_settled( $m ) ) {
								continue; // già restituito con la cancellazione della singola iscrizione: non si restituisce due volte
							}
							$d = array(
								'date' => $today, 'account_id' => (int) $m['account_id'], 'method' => (string) $m['method'], 'category_id' => $cat, 'amount_cents' => (int) $m['amount_cents'],
								'person_id' => $m['person_id'] ? (int) $m['person_id'] : 0, 'description' => mb_substr( '[Evento eliminato: ' . $name . '] Restituzione di un incasso del ' . $m['tx_date'], 0, 255 ),
							);
							if ( null !== $m['vat_rate'] && '' !== $m['vat_rate'] ) {
								$d['vat_rate'] = (int) $m['vat_rate']; // la restituzione storna anche l'IVA contenuta
							}
							$ledger->record_expense( $d );
							$sum['refunds']++;
						} else {
							$ledger->void( (int) $m['id'], 'Evento eliminato: ' . $name . ' (incasso annullato)' );
							$sum['voided']++;
						}
					} elseif ( self::VOID === $mode && self::is_refund_expense( $m ) ) {
						$ledger->void( (int) $m['id'], 'Evento eliminato: ' . $name . ' (restituzione annullata insieme all\'incasso)' ); // compensava un incasso che ora si annulla
					} elseif ( self::VOID === $mode && ! empty( $opts['void_costs'] ) ) {
						$ledger->void( (int) $m['id'], 'Evento eliminato: ' . $name . ' (spesa annullata)' );
						$sum['expenses_voided']++;
					}
				}
				// I movimenti che restano (o annullati) non puntano più a un evento che non c'è: lo si legge nella descrizione.
				$tx = Db::t( 'transactions' );
				$db->query( $db->prepare( "UPDATE $tx SET description = LEFT(CONCAT('[Evento eliminato: ', %s, '] ', description), 255), activity_id = NULL, session_id = NULL WHERE activity_id = %d AND description NOT LIKE '[Evento eliminato:%%'", $name, $activity_id ) );
				$db->query( $db->prepare( "UPDATE $tx SET activity_id = NULL, session_id = NULL WHERE activity_id = %d", $activity_id ) );

				// Fondo di rimborso del referente: le quote accantonate per questo evento cadono; il fondo si toglie se resta vuoto.
				$fund = (int) $db->get_var( $db->prepare( 'SELECT id FROM ' . Db::t( 'funds' ) . ' WHERE activity_id = %d', $activity_id ) );
				if ( $fund ) {
					$db->query( $db->prepare( 'DELETE FROM ' . Db::t( 'fund_entries' ) . " WHERE fund_id = %d AND kind = 'share'", $fund ) );
					if ( 0 === (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'fund_entries' ) . ' WHERE fund_id = %d', $fund ) ) ) {
						$db->delete( Db::t( 'funds' ), array( 'id' => $fund ) );
					} else {
						$db->update( Db::t( 'funds' ), array( 'activity_id' => null ), array( 'id' => $fund ) );
					}
				}

				if ( $sessions ) {
					$in = implode( ',', $sessions );
					$db->query( 'DELETE FROM ' . Db::t( 'bookings' ) . " WHERE session_id IN ($in)" );
					$db->query( 'DELETE FROM ' . Db::t( 'waitlist' ) . " WHERE session_id IN ($in)" );
				}
				foreach ( array( 'sessions', 'enrollments', 'activity_staff', 'attendance', 'notices' ) as $table ) {
					$db->delete( Db::t( $table ), array( 'activity_id' => $activity_id ) );
				}
				$db->delete( Db::t( 'woo_links' ), array( 'kind' => 'activity', 'ref_id' => $activity_id ) );
				$db->delete( Db::t( 'activities' ), array( 'id' => $activity_id ) );
				self::forget_in_pages( $activity_id );
				Audit::log( 'activity.deleted', 'activity', $activity_id, array( 'name' => $name, 'mode' => $mode, 'income_cents' => $sum['income_cents'], 'refunds' => $sum['refunds'], 'voided' => $sum['voided'] ) );
			}
		);

		foreach ( $mail_to as $person ) { // l'avviso parte a cose fatte, fuori dalla transazione
			$text = 'Ciao ' . $person['first_name'] . ",\n\n" . 'l\'evento «' . $name . '» è stato annullato.'
				. ( self::REFUND === $mode ? "\n\nSe avevi versato un contributo ti verrà restituito: la segreteria ti contatterà." : '' )
				. "\n\nCi dispiace per il disagio.";
			if ( Texts::mail( $person['email'], 'Evento annullato: ' . $name, $text ) ) {
				$sum['notified']++;
			}
		}
		return $sum;
	}

	// ---------- Cancellazione di una singola iscrizione (stesso criterio) ----------

	/** Incassi di una iscrizione: della persona per l'attività (per gli eventi, per la singola data). @return array[] */
	private static function registration_incomes( int $activity_id, int $person_id, int $session_id ): array {
		$sql  = 'SELECT * FROM ' . Db::t( 'transactions' ) . " WHERE activity_id = %d AND person_id = %d AND type = 'income' AND voided_at IS NULL";
		$args = array( $activity_id, $person_id );
		if ( $session_id ) {
			$sql   .= ' AND session_id = %d';
			$args[] = $session_id;
		}
		return self::db()->get_results( self::db()->prepare( $sql . ' ORDER BY id', $args ), ARRAY_A ) ?: array();
	}

	/** Cosa succederebbe cancellando l'iscrizione. @return array|null null se l'iscrizione non esiste */
	public static function registration_preview( int $activity_id, int $person_id, int $session_id = 0 ): ?array {
		$a      = Plugin::activities()->get( $activity_id );
		$person = Plugin::people()->get( $person_id );
		if ( ! $a || ! $person ) {
			return null;
		}
		$session = null;
		if ( ActivityKind::uses_sessions( $a['kind'] ) ) {
			$session = $session_id ? Plugin::activities()->session( $session_id ) : null;
			if ( ! $session || (int) $session['activity_id'] !== $activity_id || ! Plugin::activities()->booking( $session_id, $person_id ) ) {
				return null;
			}
		} else {
			$session_id = 0;
			if ( ! self::db()->get_var( self::db()->prepare( 'SELECT id FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND person_id = %d', $activity_id, $person_id ) ) ) {
				return null;
			}
		}
		$out = array( 'activity' => $a, 'person' => $person, 'session' => $session, 'session_id' => $session_id, 'income' => array( 'count' => 0, 'cents' => 0, 'online_cents' => 0, 'ids' => array(), 'receipts' => array() ), 'years' => array() );
		foreach ( self::registration_incomes( $activity_id, $person_id, $session_id ) as $m ) {
			$out['income']['count']++;
			$out['income']['cents'] += (int) $m['amount_cents'];
			$out['income']['ids'][]  = (int) $m['id'];
			if ( in_array( (string) $m['method'], self::ONLINE_METHODS, true ) ) {
				$out['income']['online_cents'] += (int) $m['amount_cents'];
			}
			if ( ! empty( $m['receipt_id'] ) ) {
				$out['income']['receipts'][ (string) $m['receipt_id'] ] = true;
			}
			$out['years'][ (int) substr( (string) $m['tx_date'], 0, 4 ) ] = true;
		}
		$out['income']['receipts'] = array_keys( $out['income']['receipts'] );
		$out['years']              = array_keys( $out['years'] );
		return $out;
	}

	/** Iscritti a un corso la cui iscrizione è finita prima del mese in corso (ad esempio chi non ha confermato). @return int[] id delle persone */
	public static function ended_enrollments( int $activity_id ): array {
		return array_map( 'intval', self::db()->get_col( self::db()->prepare(
			'SELECT person_id FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND end_month IS NOT NULL AND end_month < %s ORDER BY id',
			$activity_id,
			current_time( 'Y-m' )
		) ) );
	}

	/**
	 * Toglie dall'elenco gli iscritti con l'iscrizione già finita, ma solo se non hanno incassi registrati: la prima nota non si tocca.
	 * Chi ha incassi resta e va cancellato a mano con la scelta sulle somme.
	 *
	 * @return array{removed:int,kept:string[]} quanti tolti e i nomi di chi resta perché ha incassi
	 */
	public static function purge_ended_enrollments( int $activity_id ): array {
		$out = array( 'removed' => 0, 'kept' => array() );
		foreach ( self::ended_enrollments( $activity_id ) as $pid ) {
			$pre = self::registration_preview( $activity_id, $pid, 0 );
			if ( ! $pre ) {
				continue;
			}
			if ( $pre['income']['count'] > 0 ) {
				$out['kept'][] = Plugin::people()->full_name( $pre['person'] );
				continue;
			}
			self::delete_registration( $activity_id, $pid, 0, self::REFUND );
			$out['removed']++;
		}
		return $out;
	}

	/** @return string[] */
	public static function registration_blockers( int $activity_id, int $person_id, int $session_id, string $mode ): array {
		$p = self::registration_preview( $activity_id, $person_id, $session_id );
		if ( ! $p ) {
			return array( 'L\'iscrizione non esiste (più).' );
		}
		$years = $p['years'];
		if ( self::REFUND === $mode && $p['income']['count'] ) {
			$years[] = (int) current_time( 'Y' );
		}
		$out = array();
		foreach ( array_unique( $years ) as $y ) {
			try {
				FiscalYears::assert_open_for_date( (int) $y . '-06-15' );
			} catch ( \InvalidArgumentException $e ) {
				$out[] = $e->getMessage() . ' (Contiene incassi di questa iscrizione, o è l\'anno in cui si registrerebbe la restituzione.)';
			}
		}
		return $out;
	}

	/**
	 * Cancella un'iscrizione (prenotazione a una data, o iscrizione a un corso) e decide la sorte degli incassi collegati.
	 *
	 * @param array $opts notify (avvisa la persona se ha un'email)
	 * @return array riepilogo: income_count, income_cents, refunds, voided, notified, name
	 * @throws \InvalidArgumentException
	 */
	public static function delete_registration( int $activity_id, int $person_id, int $session_id, string $mode, array $opts = array() ): array {
		if ( ! in_array( $mode, array( self::REFUND, self::VOID ), true ) ) {
			throw new \InvalidArgumentException( 'Scegli cosa fare delle somme incassate.' );
		}
		$blockers = self::registration_blockers( $activity_id, $person_id, $session_id, $mode );
		if ( $blockers ) {
			throw new \InvalidArgumentException( $blockers[0] );
		}
		$p      = self::registration_preview( $activity_id, $person_id, $session_id );
		$a      = $p['activity'];
		$who    = Plugin::people()->full_name( $p['person'] );
		$db     = self::db();
		$ledger = Plugin::ledger();
		$sid    = (int) $p['session_id'];
		$sum    = array( 'income_count' => $p['income']['count'], 'income_cents' => $p['income']['cents'], 'refunds' => 0, 'voided' => 0, 'notified' => 0, 'name' => $who );
		$ledger->in_batch(
			function () use ( $activity_id, $person_id, $sid, $mode, $a, $who, $ledger, $db, &$sum, $p ) {
				$today = current_time( 'Y-m-d' );
				$cat   = self::REFUND === $mode ? self::refund_category() : 0;
				foreach ( self::registration_incomes( $activity_id, $person_id, $sid ) as $m ) {
					if ( self::REFUND === $mode ) {
						$d = array(
							'date' => $today, 'account_id' => (int) $m['account_id'], 'method' => (string) $m['method'], 'category_id' => $cat, 'amount_cents' => (int) $m['amount_cents'],
							'activity_id' => $activity_id, 'person_id' => $person_id,
							'description' => mb_substr( '[Iscrizione cancellata: ' . $who . '] Restituzione di un incasso del ' . $m['tx_date'], 0, 255 ),
						);
						if ( null !== $m['vat_rate'] && '' !== $m['vat_rate'] ) {
							$d['vat_rate'] = (int) $m['vat_rate'];
						}
						$ledger->record_expense( $d );
						$sum['refunds']++;
					} else {
						$ledger->void( (int) $m['id'], 'Iscrizione cancellata: ' . $who . ' (incasso annullato)' );
						$sum['voided']++;
					}
				}
				$ids = array_map( 'intval', $p['income']['ids'] );
				if ( $ids ) {
					$in = implode( ',', $ids );
					$db->query( 'UPDATE ' . Db::t( 'transactions' ) . " SET description = LEFT(CONCAT('[Iscrizione cancellata] ', description), 255) WHERE id IN ($in)" );
					$db->query( 'DELETE FROM ' . Db::t( 'fund_entries' ) . " WHERE kind = 'share' AND tx_id IN ($in)" ); // la quota accantonata per il referente cade con l'incasso
				}
				if ( $sid ) {
					$db->delete( Db::t( 'bookings' ), array( 'session_id' => $sid, 'person_id' => $person_id ) );
					$db->delete( Db::t( 'waitlist' ), array( 'session_id' => $sid, 'person_id' => $person_id ) );
				} else {
					$db->delete( Db::t( 'enrollments' ), array( 'activity_id' => $activity_id, 'person_id' => $person_id ) );
					$db->delete( Db::t( 'attendance' ), array( 'activity_id' => $activity_id, 'person_id' => $person_id ) );
				}
				Audit::log( 'registration.deleted', 'activity', $activity_id, array( 'person' => $person_id, 'session' => $sid, 'mode' => $mode, 'income_cents' => $sum['income_cents'], 'refunds' => $sum['refunds'], 'voided' => $sum['voided'] ) );
			}
		);
		if ( ! empty( $opts['notify'] ) && is_email( (string) $p['person']['email'] ) ) {
			$text = 'Ciao ' . $p['person']['first_name'] . ",\n\n" . 'la tua iscrizione a «' . $a['name'] . '»' . ( $p['session'] ? ' del ' . $p['session']['session_date'] : '' ) . ' è stata cancellata.'
				. ( self::REFUND === $mode && $p['income']['count'] ? "\n\nIl contributo che avevi versato ti verrà restituito: la segreteria ti contatterà." : '' );
			if ( Texts::mail( $p['person']['email'], 'Iscrizione cancellata: ' . $a['name'], $text ) ) {
				$sum['notified'] = 1;
			}
		}
		return $sum;
	}

	/** Toglie l'attività dalle pagine riservate agli iscritti di quell'attività. */
	private static function forget_in_pages( int $activity_id ): void {
		$db = self::db();
		foreach ( $db->get_results( $db->prepare( "SELECT post_id FROM {$db->postmeta} WHERE meta_key = %s", '_aps_access_activities' ), ARRAY_A ) ?: array() as $r ) {
			$ids = array_values( array_filter( array_map( 'intval', (array) get_post_meta( (int) $r['post_id'], '_aps_access_activities', true ) ) ) );
			if ( in_array( $activity_id, $ids, true ) ) {
				update_post_meta( (int) $r['post_id'], '_aps_access_activities', array_values( array_diff( $ids, array( $activity_id ) ) ) );
			}
		}
	}
}
