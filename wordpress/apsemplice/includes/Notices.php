<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Avvisi "ufficiali" di chi tiene un'attività (o gestisce un evento) agli iscritti: cambi di orario, annullamenti dell'ultimo momento.
 * Ogni avviso arriva per email a ciascun iscritto (singolarmente, senza mostrare gli indirizzi degli altri) e resta nella bacheca
 * "Avvisi" dell'area riservata. I destinatari sono solo gli iscritti di quell'attività (per gli eventi: i prenotati delle date
 * non ancora passate). Gli ospiti senza email ricevono l'avviso tramite il socio che li ospita.
 */
final class Notices {

	const MAX_PER_DAY   = 5;
	const MAX_SUBJECT   = 120;
	const MAX_BODY      = 2000;
	const BOARD_DAYS    = 45;

	private static function db(): \wpdb {
		return Db::db();
	}

	/** Chi può inviare avvisi per un'attività: il referente, chi gestisce l'evento, gli amministratori. */
	public static function can_send( int $activity_id ): bool {
		return current_user_can( 'apse_notify_activity', $activity_id ) || current_user_can( 'apse_manage_event', $activity_id );
	}

	/**
	 * Persone coinvolte: iscritti attivi a un corso, oppure prenotati (non annullati) delle date da oggi in poi di un evento
	 * (o di una sola data).
	 *
	 * @return array[] persone (con 'host_id' per gli ospiti)
	 */
	public static function participants( int $activity_id, ?int $session_id = null ): array {
		$db  = self::db();
		$a   = Plugin::activities()->get( $activity_id );
		$ids = array();
		if ( ! $a ) {
			return array();
		}
		if ( ActivityKind::COURSE === $a['kind'] ) {
			$ids = $db->get_col( $db->prepare( 'SELECT person_id FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND end_month IS NULL', $activity_id ) );
		} else {
			$sql  = 'SELECT DISTINCT b.person_id FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . " s ON s.id = b.session_id AND s.cancelled_at IS NULL WHERE b.status = 'booked' AND s.activity_id = %d AND s.session_date >= %s";
			$args = array( $activity_id, current_time( 'Y-m-d' ) );
			if ( $session_id ) {
				$sql   .= ' AND s.id = %d';
				$args[] = $session_id;
			}
			$ids = $db->get_col( $db->prepare( $sql, $args ) );
		}
		$out = array();
		foreach ( array_map( 'intval', $ids ?: array() ) as $pid ) {
			$p = Plugin::people()->get( $pid );
			if ( $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Indirizzi a cui arriva l'avviso: l'email di ciascun iscritto; per gli ospiti senza email, quella del socio che li ospita. Senza doppioni.
	 *
	 * @return array[] ['email' => , 'name' => ]
	 */
	public static function recipients( int $activity_id, ?int $session_id = null ): array {
		$out = array();
		foreach ( self::participants( $activity_id, $session_id ) as $p ) {
			$email = (string) $p['email'];
			$name  = trim( $p['first_name'] . ' ' . $p['last_name'] );
			if ( '' === $email && MemberType::GUEST === $p['type'] && ! empty( $p['host_person_id'] ) ) {
				$h = Plugin::people()->get( (int) $p['host_person_id'] );
				if ( $h && '' !== (string) $h['email'] ) {
					$email = (string) $h['email'];
					$name  = trim( $h['first_name'] . ' ' . $h['last_name'] );
				}
			}
			if ( '' !== $email && is_email( $email ) ) {
				$out[ Text::lower( $email ) ] = array( 'email' => $email, 'name' => $name );
			}
		}
		return array_values( $out );
	}

	/**
	 * Invia un avviso. I permessi si controllano prima ({@see can_send()}).
	 *
	 * @return array ['id', 'recipients', 'emailed']
	 * @throws \InvalidArgumentException
	 */
	public static function send( int $activity_id, ?int $session_id, string $subject, string $body ): array {
		if ( ! Edition::allows( 'official_notices' ) ) {
			throw new \InvalidArgumentException( 'L\'invio degli avvisi è sospeso perché la licenza di APSemplice non risulta in regola.' );
		}
		$a = Plugin::activities()->get( $activity_id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		$subject = trim( $subject );
		$body    = trim( $body );
		if ( '' === $subject || '' === $body ) {
			throw new \InvalidArgumentException( 'Scrivi un titolo e il testo dell\'avviso.' );
		}
		// Indirizzi web e IBAN sono il modo più comune per dirottare i pagamenti dei soci: i volontari non li possono inserire (si cambia da Impostazioni → Limiti e soglie).
		if ( ! current_user_can( Plugin::CAP_OPS ) && ! Limits::flag( 'notice_links' ) && TextGuard::has_payment_hint( $subject . "\n" . $body ) ) {
			throw new \InvalidArgumentException( 'Negli avvisi dei volontari non si possono inserire indirizzi web né coordinate bancarie. Per i pagamenti rimanda i soci all\'area riservata o alla segreteria.' );
		}
		if ( mb_strlen( $subject ) > self::MAX_SUBJECT || mb_strlen( $body ) > self::MAX_BODY ) {
			throw new \InvalidArgumentException( 'Avviso troppo lungo: titolo fino a ' . self::MAX_SUBJECT . ' caratteri, testo fino a ' . self::MAX_BODY . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$session = null;
		if ( $session_id ) {
			$session = Plugin::activities()->session( $session_id );
			if ( ! $session || (int) $session['activity_id'] !== $activity_id ) {
				throw new \InvalidArgumentException( 'Data non valida per questa attività.' );
			}
		}
		$db  = self::db();
		$day = $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'notices' ) . ' WHERE activity_id = %d AND created_at >= %s', $activity_id, gmdate( 'Y-m-d H:i:s', strtotime( Db::now() . ' -1 day' ) ) ) );
		if ( (int) $day >= Limits::get( 'notice_per_day' ) ) {
			throw new \InvalidArgumentException( 'Per questa attività sono già stati inviati ' . Limits::get( 'notice_per_day' ) . ' avvisi nelle ultime 24 ore: aspetta un po\'.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$rcpt = self::recipients( $activity_id, $session_id );
		$user = wp_get_current_user();
		$me   = Access::current_person();
		$by   = $me ? trim( $me['first_name'] . ' ' . $me['last_name'] ) : (string) $user->display_name;
		$db->insert(
			Db::t( 'notices' ),
			array(
				'activity_id' => $activity_id, 'session_id' => $session_id ?: null, 'author_user_id' => get_current_user_id() ?: null, 'author_name' => mb_substr( $by, 0, 120 ),
				'subject' => $subject, 'body' => $body, 'recipients' => count( $rcpt ), 'emailed' => 0, 'created_at' => Db::now(),
			)
		);
		$id    = (int) $db->insert_id;
		$assoc = (string) Settings::get( 'association_name' );
		$when  = $session ? ' (' . ( new \DateTimeImmutable( $session['session_date'] ) )->format( 'd/m/Y' ) . ( $session['start_time'] ? ' ore ' . $session['start_time'] : '' ) . ')' : '';
		$sent  = 0;
		foreach ( $rcpt as $r ) {
			$text = 'Ciao ' . $r['name'] . ",\n\n" . $body . "\n\n—\nAvviso di " . $by . ' per «' . $a['name'] . '»' . $when . ( '' !== $assoc ? ' — ' . $assoc : '' )
				. ".\nPer rispondere rivolgiti alla segreteria dell'associazione.";
			if ( \ApSemplice\Texts::mail( $r['email'], ( '' !== $assoc ? '[' . $assoc . '] ' : '' ) . $a['name'] . ': ' . $subject, $text ) ) {
				$sent++;
			}
			\ApSemplice\Push::notify_email( (string) $r['email'], $a['name'] . ': ' . $subject, $body );
		}
		$db->update( Db::t( 'notices' ), array( 'emailed' => $sent ), array( 'id' => $id ) );
		Audit::log( 'notice.sent', 'activity', $activity_id, array( 'notice' => $id, 'recipients' => count( $rcpt ), 'emailed' => $sent ) ); // senza il testo
		return array( 'id' => $id, 'recipients' => count( $rcpt ), 'emailed' => $sent );
	}

	/** Ultimi avvisi di un'attività. */
	public static function recent( int $activity_id, int $limit = 10 ): array {
		$db = self::db();
		return $db->get_results( $db->prepare( 'SELECT * FROM ' . Db::t( 'notices' ) . ' WHERE activity_id = %d ORDER BY id DESC LIMIT %d', $activity_id, max( 1, $limit ) ), ARRAY_A ) ?: array();
	}

	/**
	 * Bacheca di un socio: avvisi recenti delle attività a cui partecipa lui o i suoi ospiti.
	 *
	 * @return array[] avvisi con 'activity_name'
	 */
	public static function board( int $person_id, int $limit = 10 ): array {
		$people = array( $person_id );
		foreach ( Plugin::people()->guests_of( $person_id ) as $g ) {
			$people[] = (int) $g['id'];
		}
		$acts  = array();
		$today = current_time( 'Y-m-d' );
		foreach ( $people as $pid ) {
			foreach ( Plugin::activities()->participations( $pid ) as $i ) {
				if ( ! $i['ended'] || ( 'event' === $i['type'] && $i['when'] >= $today ) ) {
					$acts[ (int) $i['activity_id'] ] = true;
				}
			}
		}
		if ( ! $acts ) {
			return array();
		}
		$db  = self::db();
		$in  = implode( ',', array_map( 'intval', array_keys( $acts ) ) );
		$min = gmdate( 'Y-m-d H:i:s', strtotime( Db::now() . ' -' . Limits::get( 'notice_board_days' ) . ' days' ) );
		return $db->get_results(
			$db->prepare( 'SELECT n.*, a.name AS activity_name FROM ' . Db::t( 'notices' ) . ' n JOIN ' . Db::t( 'activities' ) . " a ON a.id = n.activity_id WHERE n.activity_id IN ($in) AND n.created_at >= %s ORDER BY n.id DESC LIMIT %d", $min, max( 1, $limit ) ),
			ARRAY_A
		) ?: array();
	}
}
