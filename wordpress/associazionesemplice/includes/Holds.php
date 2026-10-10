<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Tolleranza per il pagamento: per gli eventi con i posti limitati e un contributo, chi prenota e paga con bonifico o contanti ha un
 * certo numero di ore (si sceglie alla creazione dell'evento) per versare. Scaduto il termine senza nessun pagamento il posto si libera
 * e passa a chi è in lista d'attesa. Non si libera mai un posto con un pagamento già registrato, con un pagamento online in corso,
 * con l'ingresso già registrato o per una data già passata.
 */
final class Holds {

	/** Lavoro periodico, pianificato da Plugin::schedule_jobs(). */
	const HOOK = 'asem_release_holds';

	/** Ha un pagamento online avviato da poco per questa prenotazione? */
	private static function online_in_progress( int $person_id, int $session_id ): bool {
		$db    = Db::db();
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		foreach ( $db->get_results( $db->prepare( 'SELECT items FROM ' . Db::t( 'payments' ) . " WHERE payer_person_id = %d AND status IN ('created','pending') AND updated_at >= %s", $person_id, $since ), ARRAY_A ) ?: array() as $p ) {
			foreach ( (array) json_decode( (string) $p['items'], true ) as $it ) {
				if ( (int) ( $it['session_id'] ?? 0 ) === $session_id ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Libera i posti delle prenotazioni non pagate entro il termine.
	 *
	 * @param ?int $session_id solo questa data (null = tutte)
	 * @return int prenotazioni liberate
	 */
	public static function release_expired( ?int $session_id = null ): int {
		$db   = Db::db();
		$sql  = 'SELECT b.session_id, b.person_id, b.fee_due_cents, a.hold_hours, a.name AS activity_name, a.id AS activity_id, s.session_date FROM ' . Db::t( 'bookings' ) . ' b '
			. 'JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id AND s.cancelled_at IS NULL AND s.capacity IS NOT NULL AND s.session_date >= %s '
			. 'JOIN ' . Db::t( 'activities' ) . " a ON a.id = s.activity_id AND a.hold_hours > 0 WHERE b.status = 'booked' AND b.fee_due_cents > 0 AND b.checked_in_at IS NULL "
			. 'AND b.created_at <= DATE_SUB(%s, INTERVAL a.hold_hours HOUR)';
		$args = array( Db::today(), Db::now() );
		if ( $session_id ) {
			$sql   .= ' AND b.session_id = %d';
			$args[] = $session_id;
		}
		$n = 0;
		foreach ( $db->get_results( $db->prepare( $sql, $args ), ARRAY_A ) ?: array() as $b ) {
			$sid = (int) $b['session_id'];
			$pid = (int) $b['person_id'];
			$row = null;
			foreach ( Plugin::activities()->bookings_for_session( $sid ) as $r ) {
				if ( (int) $r['person_id'] === $pid ) {
					$row = $r;
					break;
				}
			}
			if ( ! $row || 'booked' !== $row['status'] || (int) $row['paid'] > 0 || self::online_in_progress( $pid, $sid ) ) {
				continue; // ha già versato (anche in parte) o sta pagando online: il posto resta
			}
			Plugin::activities()->cancel_booking( $sid, $pid ); // libera il posto e fa entrare il primo della lista d'attesa
			Audit::log( 'booking.released', 'activity', (int) $b['activity_id'], array( 'session' => $sid, 'person_id' => $pid, 'hours' => (int) $b['hold_hours'] ) );
			$person = Plugin::people()->get( $pid );
			if ( $person && is_email( (string) $person['email'] ) ) {
				Texts::mail(
					$person['email'],
					'Posto liberato: ' . $b['activity_name'],
					'Ciao ' . $person['first_name'] . ",\n\nil tuo posto per «" . $b['activity_name'] . '» del ' . $b['session_date'] . ' è stato liberato perché il contributo non è arrivato entro ' . (int) $b['hold_hours'] . ' ore dalla prenotazione.'
					. "\n\nSe vuoi partecipare, prenota di nuovo (se ci sono ancora posti) o mettiti in lista d'attesa."
				);
			}
			$n++;
		}
		return $n;
	}
}
