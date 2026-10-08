<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Lista d'attesa degli eventi: quando i posti sono finiti ci si mette in coda; se qualcuno annulla (o aumentano i posti)
 * entra automaticamente il primo della lista, che riceve una email. Il contributo resta da versare come per ogni prenotazione.
 */
final class Waitlist {

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function join( int $session_id, int $person_id, int $by_person_id = 0 ): void {
		$acts    = Plugin::activities();
		$session = $acts->session( $session_id );
		$person  = Plugin::people()->get( $person_id );
		if ( ! $session || ! $person || ! empty( $session['cancelled_at'] ) ) {
			throw new \InvalidArgumentException( 'Data non disponibile (inesistente o annullata).' );
		}
		if ( $session['session_date'] < current_time( 'Y-m-d' ) ) {
			throw new \InvalidArgumentException( 'Questo evento è già passato.' );
		}
		if ( null === $session['capacity'] || (int) $acts->seats( $session_id )['free'] > 0 ) {
			throw new \InvalidArgumentException( 'Ci sono ancora posti liberi: puoi prenotare direttamente.' );
		}
		if ( $acts->has_active_booking( $session_id, $person_id ) ) {
			throw new \InvalidArgumentException( 'Questa persona è già prenotata a questa data.' );
		}
		if ( null !== self::position( $session_id, $person_id ) ) {
			throw new \InvalidArgumentException( 'Questa persona è già in lista d\'attesa.' );
		}
		$tbl = Db::t( 'waitlist' );
		self::db()->query(
			self::db()->prepare(
				"INSERT INTO $tbl (session_id, person_id, requested_by, status, created_at) VALUES (%d, %d, %d, 'waiting', %s) ON DUPLICATE KEY UPDATE status = 'waiting', requested_by = VALUES(requested_by), created_at = VALUES(created_at), resolved_at = NULL",
				$session_id,
				$person_id,
				$by_person_id ?: null,
				Db::now()
			)
		);
		Audit::log( 'waitlist.joined', 'activity', (int) $session['activity_id'], array( 'session' => $session_id, 'person' => $person_id ) );
	}

	public static function leave( int $session_id, int $person_id ): void {
		self::db()->update( Db::t( 'waitlist' ), array( 'status' => 'left', 'resolved_at' => Db::now() ), array( 'session_id' => $session_id, 'person_id' => $person_id, 'status' => 'waiting' ) );
		Audit::log( 'waitlist.left', 'person', $person_id, array( 'session' => $session_id ) );
	}

	/** Posizione in coda (1 = il primo), oppure null se non è in lista. */
	public static function position( int $session_id, int $person_id ): ?int {
		$tbl = Db::t( 'waitlist' );
		$row = self::db()->get_row( self::db()->prepare( "SELECT id FROM $tbl WHERE session_id = %d AND person_id = %d AND status = 'waiting'", $session_id, $person_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return 1 + (int) self::db()->get_var( self::db()->prepare( "SELECT COUNT(*) FROM $tbl WHERE session_id = %d AND status = 'waiting' AND id < %d", $session_id, (int) $row['id'] ) );
	}

	/** In coda per una data, in ordine di arrivo. @return array[] person_id, first_name, last_name, type, since */
	public static function waiting( int $session_id ): array {
		$tbl = Db::t( 'waitlist' );
		return self::db()->get_results(
			self::db()->prepare(
				"SELECT w.person_id, w.created_at AS since, p.first_name, p.last_name, p.type FROM $tbl w JOIN " . Db::t( 'people' ) . " p ON p.id = w.person_id AND p.deleted_at IS NULL WHERE w.session_id = %d AND w.status = 'waiting' ORDER BY w.id",
				$session_id
			),
			ARRAY_A
		) ?: array();
	}

	public static function count( int $session_id ): int {
		return count( self::waiting( $session_id ) );
	}

	/**
	 * Finché ci sono posti liberi prenota il primo della lista. Chi non può più essere prenotato (tessera scaduta, sospeso, regolamento non accettato)
	 * viene saltato e tolto dalla lista.
	 *
	 * @return int prenotati
	 */
	public static function promote( int $session_id ): int {
		$acts    = Plugin::activities();
		$session = $acts->session( $session_id );
		if ( ! $session || ! empty( $session['cancelled_at'] ) || $session['session_date'] < current_time( 'Y-m-d' ) ) {
			return 0;
		}
		$n   = 0;
		$tbl = Db::t( 'waitlist' );
		foreach ( self::waiting( $session_id ) as $w ) {
			$seats = $acts->seats( $session_id );
			if ( null !== $seats['free'] && $seats['free'] <= 0 ) {
				break;
			}
			$pid    = (int) $w['person_id'];
			$people = Plugin::people();
			$p      = $people->get( $pid );
			$host   = $p && ! empty( $p['host_person_id'] ) ? $people->get( (int) $p['host_person_id'] ) : null;
			$who    = $host ?: $p; // chi risponde della prenotazione: il socio stesso o chi ospita
			$ok     = $p && $who && ! $acts->has_active_booking( $session_id, $pid )
				&& $people->is_active_member( (int) $who['id'] )
				&& '' === Regulation::booking_block( $who );
			if ( $ok ) {
				try {
					$acts->book( $session_id, $pid );
					$n++;
					self::db()->update( $tbl, array( 'status' => 'promoted', 'resolved_at' => Db::now() ), array( 'session_id' => $session_id, 'person_id' => $pid, 'status' => 'waiting' ) );
					self::notify( $p, $session );
					Audit::log( 'waitlist.promoted', 'activity', (int) $session['activity_id'], array( 'session' => $session_id, 'person' => $pid ) );
					continue;
				} catch ( \InvalidArgumentException $e ) {
					unset( $e ); // posti finiti nel frattempo: resta in coda
					break;
				}
			}
			self::db()->update( $tbl, array( 'status' => 'left', 'resolved_at' => Db::now() ), array( 'session_id' => $session_id, 'person_id' => $pid, 'status' => 'waiting' ) );
		}
		return $n;
	}

	private static function notify( array $person, array $session ): void {
		$to = Reminders::recipient( $person );
		if ( ! $to ) {
			return;
		}
		$a    = Plugin::activities()->get( (int) $session['activity_id'] );
		$who  = '' !== $to['about'] ? 'per ' . $to['about'] . ' ' : '';
		$when = ( new \DateTimeImmutable( $session['session_date'] ) )->format( 'd/m/Y' ) . ( $session['start_time'] ? ' alle ' . $session['start_time'] : '' );
		$assoc = (string) Settings::get( 'association_name' );
		if ( Edition::has( 'pwa' ) ) {
			Push::notify_email( (string) $to['email'], 'Posto disponibile: ' . ( $a ? $a['name'] : 'evento' ), 'Si è liberato un posto e la prenotazione ' . $who . 'del ' . $when . ' è stata registrata.' );
		}
		Texts::mail(
			$to['email'],
			'Posto disponibile: ' . ( $a ? $a['name'] : 'evento' ),
			'Ciao ' . $to['name'] . ",\n\nsi è liberato un posto e la prenotazione " . $who . 'per «' . ( $a ? $a['name'] : 'evento' ) . '» del ' . $when . " è stata registrata automaticamente.\n\n"
			. "Se non puoi più partecipare, annulla dall'area riservata: " . Gatekeeper::area_url() . ( '' !== $assoc ? "\n\n—\n" . $assoc : '' )
		);
	}
}
