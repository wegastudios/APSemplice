<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Generatore di calendari iCalendar (RFC 5545), leggibili da Google Calendar, Apple Calendar, Outlook. */
final class Ics {

	const DEFAULT_MINUTES = 120; // durata proposta quando non è indicato l'orario di fine

	public static function escape( string $s ): string {
		$s = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\;', '\,' ), $s );
		return str_replace( array( "\r\n", "\n", "\r" ), '\n', $s );
	}

	/** Righe lunghe al massimo 75 byte, continuate con uno spazio (senza spezzare i caratteri UTF-8). */
	public static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out   = '';
		$chunk = '';
		$limit = 75;
		foreach ( preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			if ( strlen( $chunk ) + strlen( $ch ) > $limit ) {
				$out  .= $chunk . "\r\n ";
				$chunk = '';
				$limit = 74;
			}
			$chunk .= $ch;
		}
		return $out . $chunk;
	}

	/**
	 * @param array  $e   uid, summary, date (Y-m-d), start (HH:MM|null), end (HH:MM|null), location?, description?, url?
	 * @param string $tz  fuso orario dei dati (es. Europe/Rome)
	 * @param string $now data e ora UTC di generazione (Ymd\THis\Z)
	 */
	public static function event( array $e, string $tz, string $now ): string {
		$zone  = new \DateTimeZone( $tz );
		$lines = array( 'BEGIN:VEVENT', 'UID:' . $e['uid'], 'DTSTAMP:' . $now );
		if ( ! empty( $e['start'] ) ) {
			$start = new \DateTimeImmutable( $e['date'] . ' ' . $e['start'], $zone );
			$end   = ! empty( $e['end'] ) ? new \DateTimeImmutable( $e['date'] . ' ' . $e['end'], $zone ) : $start->modify( '+' . self::DEFAULT_MINUTES . ' minutes' );
			if ( $end <= $start ) {
				$end = $start->modify( '+' . self::DEFAULT_MINUTES . ' minutes' );
			}
			$utc     = new \DateTimeZone( 'UTC' );
			$lines[] = 'DTSTART:' . $start->setTimezone( $utc )->format( 'Ymd\THis\Z' );
			$lines[] = 'DTEND:' . $end->setTimezone( $utc )->format( 'Ymd\THis\Z' );
		} else {
			$day     = new \DateTimeImmutable( $e['date'] );
			$lines[] = 'DTSTART;VALUE=DATE:' . $day->format( 'Ymd' );
			$lines[] = 'DTEND;VALUE=DATE:' . $day->modify( '+1 day' )->format( 'Ymd' );
		}
		$lines[] = 'SUMMARY:' . self::escape( (string) $e['summary'] );
		if ( ! empty( $e['location'] ) ) {
			$lines[] = 'LOCATION:' . self::escape( (string) $e['location'] );
		}
		if ( ! empty( $e['description'] ) ) {
			$lines[] = 'DESCRIPTION:' . self::escape( (string) $e['description'] );
		}
		if ( ! empty( $e['url'] ) ) {
			$lines[] = 'URL:' . $e['url'];
		}
		$lines[] = 'END:VEVENT';
		return implode( "\r\n", array_map( array( __CLASS__, 'fold' ), $lines ) ) . "\r\n";
	}

	public static function calendar( string $name, array $events, string $tz, string $now ): string {
		$out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//AssociazioneSemplice//Calendario//IT\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n"
			. self::fold( 'X-WR-CALNAME:' . self::escape( $name ) ) . "\r\n" . 'X-WR-TIMEZONE:' . $tz . "\r\n";
		foreach ( $events as $e ) {
			$out .= self::event( $e, $tz, $now );
		}
		return $out . "END:VCALENDAR\r\n";
	}
}
