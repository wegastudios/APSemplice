<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Promemoria automatici per email, una volta al giorno (spenti di default, si accendono dalle impostazioni):
 *  - tessera in scadenza (entro N giorni) e tessera scaduta da poco: una volta per scadenza;
 *  - corsi con rinnovo mensile: dopo l'ultima lezione del mese, a chi non ha ancora pagato il mese dopo (una volta per mese);
 *  - evento il giorno dopo: una volta per prenotazione.
 * Chi non ha email (un ospite) riceve il promemoria tramite il socio che lo ospita. Gli invii sono registrati per non ripeterli.
 */
final class Reminders {

	const HOOK            = 'apse_send_reminders';
	const OPT_LAST        = 'apse_reminders_last';
	const EXPIRED_WINDOW  = 7; // giorni dopo la scadenza in cui si manda ancora il promemoria "scaduta"

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 600, 'daily', self::HOOK );
		}
	}

	public static function enabled(): bool {
		return ! empty( Settings::get( 'reminders_enabled' ) );
	}

	// ---------- Registro degli invii ----------

	private static function sent( int $person_id, string $kind, string $ref ): bool {
		$db = Db::db();
		return (bool) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'reminders' ) . ' WHERE person_id = %d AND kind = %s AND ref = %s', $person_id, $kind, $ref ) );
	}

	private static function sent_since( int $person_id, string $kind, string $since ): bool {
		$db = Db::db();
		return (bool) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'reminders' ) . ' WHERE person_id = %d AND kind = %s AND sent_at >= %s', $person_id, $kind, $since ) );
	}

	private static function mark( int $person_id, string $kind, string $ref ): void {
		Db::db()->query( Db::db()->prepare( 'INSERT IGNORE INTO ' . Db::t( 'reminders' ) . ' (person_id, kind, ref, sent_at) VALUES (%d, %s, %s, %s)', $person_id, $kind, substr( $ref, 0, 60 ), Db::now() ) );
	}

	/** Dove mandare il promemoria di una persona: la sua email o, per un ospite senza email, quella del socio che lo ospita. @return array{email:string,name:string,about:string}|null */
	public static function recipient( array $p ): ?array {
		if ( ! empty( $p['anonymized_at'] ) ) {
			return null;
		}
		$name  = trim( $p['first_name'] . ' ' . $p['last_name'] );
		$email = (string) $p['email'];
		if ( '' !== $email && is_email( $email ) ) {
			return array( 'email' => $email, 'name' => $p['first_name'], 'about' => '' );
		}
		if ( MemberType::GUEST === $p['type'] && ! empty( $p['host_person_id'] ) ) {
			$h = Plugin::people()->get( (int) $p['host_person_id'] );
			if ( $h && '' !== (string) $h['email'] && is_email( (string) $h['email'] ) ) {
				return array( 'email' => (string) $h['email'], 'name' => $h['first_name'], 'about' => $name );
			}
		}
		return null;
	}

	private static function send( array $to, string $subject, string $body ): bool {
		$assoc = (string) Settings::get( 'association_name' );
		$text  = 'Ciao ' . $to['name'] . ",\n\n" . $body . "\n\n—\n" . ( '' !== $assoc ? $assoc . "\n" : '' ) . 'Area riservata: ' . Gatekeeper::area_url() . "\nPer informazioni rivolgiti alla segreteria.";
		return (bool) \ApSemplice\Texts::mail( $to['email'], ( '' !== $assoc ? '[' . $assoc . '] ' : '' ) . $subject, $text );
	}

	// ---------- Esecuzione ----------

	/**
	 * Cerca i promemoria da mandare oggi e (se $send) li manda. @return array membership, dues, events (conteggi di quelli mandati o, senza invio, da mandare)
	 */
	public static function run( ?string $today = null, bool $send = true ): array {
		$out = array( 'membership' => 0, 'dues' => 0, 'events' => 0 );
		if ( $send && ! self::enabled() ) {
			return $out;
		}
		if ( $send && ! License::allows( 'official_notices' ) ) {
			return $out;
		}
		$today = $today ?: current_time( 'Y-m-d' );
		if ( ! empty( Settings::get( 'reminders_membership' ) ) ) {
			$out['membership'] = self::membership( $today, $send );
		}
		if ( ! empty( Settings::get( 'reminders_dues' ) ) ) {
			$out['dues'] = self::dues( $today, $send );
		}
		if ( ! empty( Settings::get( 'reminders_events' ) ) ) {
			$out['events'] = self::events( $today, $send );
		}
		if ( $send ) {
			update_option( self::OPT_LAST, array( 'at' => Db::now(), 'counts' => $out ), false );
			Audit::log( 'reminders.sent', 'settings', 0, $out );
		}
		return $out;
	}

	public static function last_run(): ?array {
		$v = get_option( self::OPT_LAST, null );
		return is_array( $v ) ? $v : null;
	}

	private static function membership( string $today, bool $send ): int {
		$days  = (int) Settings::get( 'reminders_membership_days' );
		$soon  = gmdate( 'Y-m-d', strtotime( $today . ' +' . $days . ' days' ) );
		$ago   = gmdate( 'Y-m-d', strtotime( $today . ' -' . self::EXPIRED_WINDOW . ' days' ) );
		$n     = 0;
		foreach ( Plugin::people()->search() as $p ) {
			if ( ! MemberType::is_member( $p['type'] ) || MemberType::is_auto_renewed( $p['type'] ) || ! empty( $p['suspended_at'] ) || empty( $p['active_until'] ) ) {
				continue;
			}
			$until = (string) $p['active_until'];
			if ( $until >= $today && $until <= $soon ) {
				$kind = 'membership_soon';
				$subj = 'La tua tessera sta per scadere';
				$body = 'la tua tessera associativa scade il ' . ( new \DateTimeImmutable( $until ) )->format( 'd/m/Y' ) . '. Puoi rinnovarla in segreteria o, se attivi, online dall\'area riservata.';
			} elseif ( $until < $today && $until >= $ago ) {
				$kind = 'membership_expired';
				$subj = 'La tua tessera è scaduta';
				$body = 'la tua tessera associativa è scaduta il ' . ( new \DateTimeImmutable( $until ) )->format( 'd/m/Y' ) . '. Per prenotare eventi e corsi serve rinnovarla: rivolgiti alla segreteria.';
			} else {
				continue;
			}
			$to = self::recipient( $p );
			if ( ! $to || self::sent( (int) $p['id'], $kind, $until ) ) {
				continue;
			}
			if ( $send ) {
				if ( ! self::send( $to, $subj, $body ) ) {
					continue;
				}
				self::mark( (int) $p['id'], $kind, $until );
			}
			$n++;
		}
		return $n;
	}

	/**
	 * Corsi con rinnovo mensile: dopo l'ULTIMA lezione del mese si ricorda a chi non ha ancora pagato il mese dopo (si rinnovano a inizio mese).
	 * Un solo promemoria per persona, corso e mese; non si manda per un corso "una tantum", né oltre la fine dell'anno sociale o del corso.
	 */
	private static function dues( string $today, bool $send ): int {
		$acts   = Plugin::activities();
		$people = Plugin::people();
		$cur    = substr( $today, 0, 7 );
		$target = gmdate( 'Y-m', strtotime( $cur . '-01 +1 month' ) );
		$mstart = $cur . '-01';
		$mend   = gmdate( 'Y-m-t', strtotime( $mstart ) );
		$last   = array(); // attività => data dell'ultima lezione del mese
		$any    = array(); // attività che hanno lezioni in calendario questo mese
		foreach ( Calendar::occurrences( $mstart, $mend ) as $o ) {
			if ( 'course' === $o['kind'] ) {
				$any[ (int) $o['activity_id'] ] = true;
				$last[ (int) $o['activity_id'] ] = max( $last[ (int) $o['activity_id'] ] ?? '', $o['date'] );
			}
		}
		$n = 0;
		foreach ( $acts->for_year( Settings::social_year( $today )->label() ) as $a ) {
			$aid = (int) $a['id'];
			if ( ActivityKind::COURSE !== $a['kind'] || 'once' === ( $a['billing'] ?? 'monthly' ) ) {
				continue;
			}
			if ( ! in_array( $target, SocialYear::from_label( $a['social_year'], Settings::start_month() )->months(), true ) ) {
				continue; // il mese dopo è nell'anno sociale successivo
			}
			if ( ! empty( $a['ends_on'] ) && substr( (string) $a['ends_on'], 0, 7 ) < $target ) {
				continue; // il corso finisce prima
			}
			$last_lesson = isset( $any[ $aid ] ) ? $last[ $aid ] : ( ! empty( ActivityService::slot_days( $a ) ) ? '' : $cur . '-24' ); // senza orari di lezione: dal 25 del mese
			if ( '' === $last_lesson || $today <= $last_lesson ) {
				continue; // l'ultima lezione del mese non c'è ancora stata (o non ci sono lezioni questo mese)
			}
			foreach ( $acts->status_for_activity( $aid ) as $s ) {
				$e = $s['enrollment'];
				if ( null !== $e['end_month'] || $e['start_month'] > $target ) {
					continue;
				}
				$p = $people->get( (int) $e['person_id'] );
				if ( ! $p || ! empty( $p['suspended_at'] ) ) {
					continue;
				}
				$fee  = $acts->fee_for( $a, $p['type'] );
				$paid = 0;
				foreach ( $s['summary']['months'] as $m ) {
					if ( $m['month'] === $target ) {
						$paid = (int) $m['paid'];
					}
				}
				if ( $fee <= 0 || $paid >= $fee ) {
					continue;
				}
				$to  = self::recipient( $p );
				$ref = $aid . ':' . $target;
				if ( ! $to || self::sent( (int) $p['id'], 'dues', $ref ) ) {
					continue;
				}
				$who  = '' !== $to['about'] ? 'per ' . $to['about'] . ' ' : '';
				$body = 'il corso «' . $a['name'] . '» si rinnova all\'inizio di ' . self::month_name( $target ) . ': ' . $who . 'la mensilità è di ' . Money::format( $fee - $paid )
					. ( $paid > 0 ? ' (resta da versare)' : '' ) . ". Puoi pagare in segreteria o, se attivo, online dall'area riservata.\nSe non vuoi più frequentare, comunicalo alla segreteria prima dell'inizio del mese. Se hai già pagato, ignora questo messaggio.";
				if ( $send ) {
					if ( ! self::send( $to, 'Rinnovo del corso ' . $a['name'], $body ) ) {
						continue;
					}
					self::mark( (int) $p['id'], 'dues', $ref );
				}
				$n++;
			}
		}
		return $n;
	}

	private static function month_name( string $ym ): string {
		return ( Receipts::MONTHS[ (int) substr( $ym, 5, 2 ) ] ?? $ym ) . ' ' . substr( $ym, 0, 4 );
	}

	private static function events( string $today, bool $send ): int {
		$tomorrow = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) );
		$db       = Db::db();
		$rows     = $db->get_results(
			$db->prepare(
				'SELECT b.id AS booking_id, b.person_id, s.id AS session_id, s.session_date, s.start_time, s.location, a.name AS activity_name FROM ' . Db::t( 'bookings' ) . ' b '
				. 'JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id AND s.cancelled_at IS NULL JOIN ' . Db::t( 'activities' ) . " a ON a.id = s.activity_id AND a.deleted_at IS NULL WHERE b.status = 'booked' AND s.session_date = %s",
				$tomorrow
			),
			ARRAY_A
		) ?: array();
		$n = 0;
		foreach ( $rows as $r ) {
			$p = Plugin::people()->get( (int) $r['person_id'] );
			if ( ! $p ) {
				continue;
			}
			$to = self::recipient( $p );
			if ( ! $to || self::sent( (int) $p['id'], 'event', (string) $r['session_id'] ) ) {
				continue;
			}
			$who  = '' !== $to['about'] ? 'per ' . $to['about'] . ' ' : '';
			$body = 'ti ricordiamo la prenotazione ' . $who . 'per «' . $r['activity_name'] . '» domani, ' . ( new \DateTimeImmutable( $r['session_date'] ) )->format( 'd/m/Y' )
				. ( $r['start_time'] ? ' alle ' . $r['start_time'] : '' ) . ( $r['location'] ? ' — ' . $r['location'] : '' ) . '. Se non puoi venire, annulla dall\'area riservata (se l\'evento lo permette).';
			if ( $send ) {
				if ( ! self::send( $to, 'Domani: ' . $r['activity_name'], $body ) ) {
					continue;
				}
				self::mark( (int) $p['id'], 'event', (string) $r['session_id'] );
			}
			$n++;
		}
		return $n;
	}
}
