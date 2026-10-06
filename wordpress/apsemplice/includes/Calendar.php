<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Calendario di corsi ed eventi: le lezioni settimanali dei corsi (giorno, orario, luogo, date di inizio e fine) e le date degli eventi.
 * Si legge in amministrazione e si collega a Google Calendar (o ad altri) con un indirizzo "da URL" segreto.
 */
final class Calendar {

	const SALT_OPTION = 'apse_ical_salt';
	const QUERY_VAR   = 'apse_ical';

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 1 );
	}

	// ---------- Date ----------

	/**
	 * Lezioni e date degli eventi tra due date (incluse), in ordine di data e ora.
	 *
	 * @return array[] date, start, end, title, location, kind (course|event|recurring), activity_id, session_id
	 */
	public static function occurrences( string $from, string $to ): array {
		$db   = Db::db();
		$out  = array();
		$acts = $db->get_results( 'SELECT * FROM ' . Db::t( 'activities' ) . ' WHERE deleted_at IS NULL', ARRAY_A ) ?: array();
		foreach ( $acts as $a ) {
			if ( ActivityKind::COURSE !== $a['kind'] ) {
				continue;
			}
			$year  = SocialYear::from_label( $a['social_year'], Settings::start_month() );
			$base0 = $a['starts_on'] ?: $year->start()->format( 'Y-m-d' );
			$base1 = $a['ends_on'] ?: $year->end()->format( 'Y-m-d' );
			foreach ( ActivityService::lessons( $a ) as $slot ) {
				if ( 'single' === $slot['type'] ) {
					if ( $slot['date'] >= $from && $slot['date'] <= $to ) {
						$out[] = array(
							'date' => $slot['date'], 'start' => $slot['start'], 'end' => $slot['end'], 'title' => $a['name'], 'location' => (string) $a['location'],
							'kind' => 'course', 'activity_id' => (int) $a['id'], 'session_id' => 0,
						);
					}
					continue;
				}
				$first = max( $from, $slot['from'] ?: $base0 );
				$last  = min( $to, $slot['until'] ?: $base1 );
				foreach ( self::weekly_dates( $first, $last, (int) $slot['day'] ) as $d ) {
					$out[] = array(
						'date' => $d, 'start' => $slot['start'], 'end' => $slot['end'], 'title' => $a['name'], 'location' => (string) $a['location'],
						'kind' => 'course', 'activity_id' => (int) $a['id'], 'session_id' => 0,
					);
				}
			}
		}
		$rows = $db->get_results(
			$db->prepare(
				'SELECT s.*, a.name, a.kind FROM ' . Db::t( 'sessions' ) . ' s JOIN ' . Db::t( 'activities' ) . ' a ON a.id = s.activity_id AND a.deleted_at IS NULL '
				. 'WHERE s.cancelled_at IS NULL AND s.session_date BETWEEN %s AND %s',
				$from,
				$to
			),
			ARRAY_A
		) ?: array();
		foreach ( $rows as $s ) {
			$out[] = array(
				'date' => $s['session_date'], 'start' => $s['start_time'], 'end' => $s['end_time'], 'title' => $s['name'], 'location' => (string) $s['location'],
				'kind' => $s['kind'], 'activity_id' => (int) $s['activity_id'], 'session_id' => (int) $s['id'],
			);
		}
		usort(
			$out,
			function ( $x, $y ) {
				return strcmp( $x['date'] . ( $x['start'] ?: '00:00' ) . $x['title'], $y['date'] . ( $y['start'] ?: '00:00' ) . $y['title'] );
			}
		);
		return $out;
	}

	/** Date comprese tra $from e $to che cadono nel giorno della settimana indicato (1 = lunedì … 7 = domenica). @return string[] */
	public static function weekly_dates( string $from, string $to, int $weekday ): array {
		$out = array();
		if ( $from > $to || $weekday < 1 || $weekday > 7 ) {
			return $out;
		}
		$d     = new \DateTimeImmutable( $from );
		$delta = ( $weekday - (int) $d->format( 'N' ) + 7 ) % 7;
		$d     = $d->modify( '+' . $delta . ' days' );
		$end   = new \DateTimeImmutable( $to );
		while ( $d <= $end ) {
			$out[] = $d->format( 'Y-m-d' );
			$d     = $d->modify( '+7 days' );
		}
		return $out;
	}

	// ---------- Collegamento a Google Calendar ----------

	public static function enabled(): bool {
		return ! empty( Settings::get( 'ical_enabled' ) );
	}

	/** Codice segreto dell'indirizzo del calendario: si può rigenerare (il vecchio indirizzo smette di funzionare). */
	public static function token(): string {
		$salt = (string) get_option( self::SALT_OPTION, '' );
		if ( '' === $salt ) {
			$salt = bin2hex( random_bytes( 16 ) );
			update_option( self::SALT_OPTION, $salt, false );
		}
		return substr( hash_hmac( 'sha256', 'ical|' . $salt, wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function regenerate_token(): void {
		update_option( self::SALT_OPTION, bin2hex( random_bytes( 16 ) ), false );
		Audit::log( 'calendar.token_regenerated', 'settings' );
	}

	/** Indirizzo del calendario di tutte le attività o, con $activity_id, di una sola. */
	public static function feed_url( ?int $activity_id = null ): string {
		$args = array( self::QUERY_VAR => self::token() );
		if ( $activity_id ) {
			$args['a'] = $activity_id;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/** Indirizzo con lo schema webcal:// (Apple Calendar, Outlook, Thunderbird). */
	public static function webcal_url( string $feed ): string {
		return preg_replace( '#^https?://#i', 'webcal://', $feed );
	}

	/** Collegamento che apre Google Calendar già pronto ad aggiungere il calendario. */
	public static function google_add_url( string $feed ): string {
		return 'https://calendar.google.com/calendar/r?cid=' . rawurlencode( self::webcal_url( $feed ) );
	}

	/** Il calendario in formato iCalendar: da 30 giorni fa a 13 mesi avanti. */
	public static function ics( ?string $today = null, ?int $activity_id = null ): string {
		$today  = $today ?: Db::today();
		$from   = ( new \DateTimeImmutable( $today ) )->modify( '-30 days' )->format( 'Y-m-d' );
		$to     = ( new \DateTimeImmutable( $today ) )->modify( '+13 months' )->format( 'Y-m-d' );
		$tz     = wp_timezone_string();
		$tz     = in_array( $tz, timezone_identifiers_list(), true ) ? $tz : 'Europe/Rome';
		$events = array();
		foreach ( self::occurrences( $from, $to ) as $o ) {
			if ( $activity_id && (int) $o['activity_id'] !== $activity_id ) {
				continue;
			}
			$events[] = array(
				'uid' => 'apse-' . ( $o['session_id'] ? 's' . $o['session_id'] : 'a' . $o['activity_id'] . '-' . $o['date'] ) . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
				'summary' => $o['title'], 'date' => $o['date'], 'start' => $o['start'], 'end' => $o['end'], 'location' => $o['location'],
			);
		}
		$name  = (string) Settings::get( 'association_name' );
		$act   = $activity_id ? Plugin::activities()->get( $activity_id ) : null;
		$title = $act ? $act['name'] : 'Corsi ed eventi';
		return Ics::calendar( ( '' !== $name ? $name . ' — ' : '' ) . $title, $events, $tz, gmdate( 'Ymd\THis\Z' ) );
	}

	/** Risponde all'indirizzo segreto del calendario. */
	public static function serve(): void {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$given = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! self::enabled() || ! hash_equals( self::token(), $given ) ) {
			status_header( 404 );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="apsemplice.ics"' );
		echo self::ics( null, isset( $_GET['a'] ) ? (int) $_GET['a'] : null ); // phpcs:ignore WordPress.Security.EscapeOutput -- iCalendar già escapato
		exit;
	}
}
