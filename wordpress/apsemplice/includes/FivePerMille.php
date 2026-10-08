<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * 5x1000: il messaggio per chiedere la firma ai soci (con il codice fiscale dell'associazione) e il registro dei contributi ricevuti,
 * con la scadenza del rendiconto sull'utilizzo (entro 12 mesi dall'accredito).
 */
final class FivePerMille {

	const OPEN     = 'open';
	const DUE_SOON = 'due_soon';
	const OVERDUE  = 'overdue';
	const REPORTED = 'reported';
	const SOON     = 60; // giorni

	const DEFAULT_TEXT = 'Nella dichiarazione dei redditi puoi destinare il 5x1000 a {associazione}: non costa nulla e per noi vale molto. Firma nel riquadro «Sostegno degli enti del Terzo settore» e scrivi il codice fiscale {codice_fiscale}.';

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function enabled(): bool {
		return (bool) Settings::get( 'fivepm_enabled' );
	}

	public static function text(): string {
		$t = trim( (string) Settings::get( 'fivepm_text' ) );
		$t = '' === $t ? self::DEFAULT_TEXT : $t;
		return strtr( $t, array( '{associazione}' => (string) Settings::get( 'association_name' ) ?: 'la nostra associazione', '{codice_fiscale}' => (string) Settings::get( 'tax_code' ) ) );
	}

	private static function date( $v ): ?string {
		$v  = trim( (string) $v );
		$dt = \DateTime::createFromFormat( 'Y-m-d', $v );
		return $dt && $dt->format( 'Y-m-d' ) === $v ? $v : null;
	}

	/** @throws \InvalidArgumentException */
	public static function add( int $year, string $amount, int $choices, string $received_on ): int {
		$now = (int) substr( Db::today(), 0, 4 );
		if ( $year < 2006 || $year > $now + 1 ) {
			throw new \InvalidArgumentException( 'Indica l\'anno di imposta del 5x1000 (ad esempio 2023).' );
		}
		$cents = Money::parse( $amount );
		if ( null === $cents || $cents <= 0 ) {
			throw new \InvalidArgumentException( 'Indica l\'importo ricevuto.' );
		}
		if ( $choices < 0 || $choices > 1000000 ) {
			throw new \InvalidArgumentException( 'Numero di scelte non valido.' );
		}
		$rec = self::date( $received_on );
		if ( ! $rec ) {
			throw new \InvalidArgumentException( 'Indica la data in cui è stato accreditato.' );
		}
		if ( self::db()->get_var( self::db()->prepare( 'SELECT id FROM ' . Db::t( 'fivepm' ) . ' WHERE year = %d', $year ) ) ) {
			throw new \InvalidArgumentException( 'Il 5x1000 dell\'anno ' . $year . ' è già registrato.' );
		}
		$ok = self::db()->insert( Db::t( 'fivepm' ), array( 'year' => $year, 'amount_cents' => $cents, 'choices' => $choices, 'received_on' => $rec, 'created_at' => Db::now() ) );
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'Impossibile salvare (errore del database).' );
		}
		$id = (int) self::db()->insert_id;
		Audit::log( 'fivepm.added', 'fivepm', $id, array( 'year' => $year, 'cents' => $cents ) );
		return $id;
	}

	/** Registra il rendiconto sull'utilizzo del contributo. @throws \InvalidArgumentException */
	public static function report( int $id, string $date, string $notes ): void {
		$row = self::get( $id );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Contributo non trovato.' );
		}
		$d = self::date( $date );
		if ( ! $d ) {
			throw new \InvalidArgumentException( 'Indica la data del rendiconto.' );
		}
		$notes = trim( sanitize_textarea_field( $notes ) );
		if ( '' === $notes ) {
			throw new \InvalidArgumentException( 'Racconta come è stato utilizzato il contributo.' );
		}
		self::db()->update( Db::t( 'fivepm' ), array( 'reported_on' => $d, 'report_notes' => mb_substr( $notes, 0, 5000 ) ), array( 'id' => $id ) );
		Audit::log( 'fivepm.reported', 'fivepm', $id, array() );
	}

	public static function delete( int $id ): void {
		if ( ! self::get( $id ) ) {
			throw new \InvalidArgumentException( 'Contributo non trovato.' );
		}
		self::db()->delete( Db::t( 'fivepm' ), array( 'id' => $id ) );
		Audit::log( 'fivepm.deleted', 'fivepm', $id, array() );
	}

	public static function get( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'fivepm' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	/** @return array[] dal più recente */
	public static function all(): array {
		return self::db()->get_results( 'SELECT * FROM ' . Db::t( 'fivepm' ) . ' ORDER BY year DESC', ARRAY_A ) ?: array();
	}

	/** Entro quando va fatto il rendiconto: 12 mesi dall'accredito. */
	public static function due_date( array $row ): string {
		return ( new \DateTimeImmutable( (string) $row['received_on'] ) )->modify( '+12 months' )->format( 'Y-m-d' );
	}

	public static function status( array $row, ?string $today = null ): string {
		$today = $today ?: Db::today();
		if ( ! empty( $row['reported_on'] ) ) {
			return self::REPORTED;
		}
		$due = self::due_date( $row );
		if ( $due < $today ) {
			return self::OVERDUE;
		}
		$soon = ( new \DateTimeImmutable( $today ) )->modify( '+' . self::SOON . ' days' )->format( 'Y-m-d' );
		return $due <= $soon ? self::DUE_SOON : self::OPEN;
	}

	/** Contributi il cui rendiconto è scaduto o sta per scadere. @return array[] */
	public static function alerts( ?string $today = null ): array {
		$out = array();
		foreach ( self::all() as $r ) {
			$s = self::status( $r, $today );
			if ( self::OVERDUE === $s || self::DUE_SOON === $s ) {
				$out[] = $r + array( 'status' => $s, 'due' => self::due_date( $r ) );
			}
		}
		return $out;
	}
}
