<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Ingresso sul posto: chi si presenta a un evento senza aver prenotato (un amico dell'ultimo minuto) viene prenotato — se c'è posto —,
 * paga il biglietto e fa il suo ingresso. Lo usano gli amministratori e lo staff dell'evento autorizzato a incassare.
 * Tutto insieme: se qualcosa non va (posti esauriti, conto non valido…) non resta scritto nulla.
 */
final class DoorSales {

	/**
	 * @param array $p session_id, activity_id, person_id (esistente) oppure new_first_name / new_last_name / new_phone + host_person_id (nuovo ospite),
	 *                 pay (bool), account_id, method (facoltativo), checkin (bool)
	 * @param bool  $cash_only true per lo staff: si incassa solo su conti di tipo cassa contanti o POS
	 * @return string messaggio con l'esito
	 * @throws \InvalidArgumentException
	 */
	public static function sell( array $p, bool $cash_only = false ): string {
		$sid     = (int) ( $p['session_id'] ?? 0 );
		$aid     = (int) ( $p['activity_id'] ?? 0 );
		$svc     = Plugin::activities();
		$session = $svc->session( $sid );
		if ( ! $session || (int) $session['activity_id'] !== $aid ) {
			throw new \InvalidArgumentException( 'Data non trovata.' );
		}
		if ( ! empty( $p['pay'] ) && $cash_only ) {
			$acc = Plugin::ledger()->account( (int) ( $p['account_id'] ?? 0 ) );
			if ( ! $acc || ! in_array( $acc['type'], array( 'cash', 'pos' ), true ) ) {
				throw new \InvalidArgumentException( 'Sul posto si incassa in contanti o con il POS: scegli un conto di quel tipo.' );
			}
		}
		$msg = '';
		Plugin::ledger()->in_batch(
			function () use ( $p, $sid, $aid, $svc, $cash_only, &$msg ) {
				$people = Plugin::people();
				$ledger = Plugin::ledger();
				$pid    = (int) ( $p['person_id'] ?? 0 );
				$first  = trim( (string) ( $p['new_first_name'] ?? '' ) );
				$last   = trim( (string) ( $p['new_last_name'] ?? '' ) );
				if ( ! $pid && $cash_only && ( '' !== $first || '' !== $last ) ) {
					throw new \InvalidArgumentException( 'Sul posto lo staff incassa solo dai soci: gli ospiti li gestisce la segreteria.' );
				}
				if ( ! $pid && ( '' !== $first || '' !== $last ) ) {
					$host = (int) ( $p['host_person_id'] ?? 0 );
					if ( ! $host ) {
						throw new \InvalidArgumentException( 'Indica il socio che ospita il nuovo ospite.' );
					}
					$pid = $people->create( array( 'type' => MemberType::GUEST, 'host_person_id' => $host, 'first_name' => $first, 'last_name' => $last, 'phone' => (string) ( $p['new_phone'] ?? '' ) ) );
				}
				if ( ! $pid ) {
					throw new \InvalidArgumentException( 'Scegli una persona oppure inserisci un nuovo ospite.' );
				}
				if ( $cash_only ) {
					$who = $people->get( $pid );
					if ( ! $who || ! MemberType::is_member( $who['type'] ) ) {
						throw new \InvalidArgumentException( 'Sul posto lo staff incassa solo dai soci: gli ospiti li gestisce la segreteria.' );
					}
				}
				if ( ! $svc->has_active_booking( $sid, $pid ) ) {
					$svc->book( $sid, $pid ); // se i posti sono finiti lancia "Posti esauriti"
				}
				$row = null;
				foreach ( $svc->bookings_for_session( $sid ) as $b ) {
					if ( (int) $b['person_id'] === $pid ) {
						$row = $b;
					}
				}
				$person = $people->get( $pid );
				$parts  = array( 'prenotato' );
				if ( ! empty( $p['pay'] ) ) {
					$due = $row ? (int) $row['remaining'] : 0;
					if ( $due > 0 ) {
						$ledger->record_receipt(
							array(
								'date' => current_time( 'Y-m-d' ), 'account_id' => (int) ( $p['account_id'] ?? 0 ), 'method' => (string) ( $p['method'] ?? '' ), 'person_id' => $pid,
								'lines' => array( array( 'category_id' => $ledger->category_id_of_kind( 'activity_fee' ), 'amount_cents' => $due, 'activity_id' => $aid, 'session_id' => $sid ) ),
							)
						);
						$parts[] = 'incassati ' . Money::format( $due );
					} else {
						$parts[] = 'nulla da incassare';
					}
				}
				if ( ! empty( $p['checkin'] ) ) {
					$svc->check_in( $sid, $pid, false, true );
					$parts[] = 'ingresso registrato';
				}
				$msg = 'Sul posto: ' . trim( $person['first_name'] . ' ' . $person['last_name'] ) . ' — ' . implode( ', ', $parts ) . '.';
			}
		);
		return $msg;
	}
}
