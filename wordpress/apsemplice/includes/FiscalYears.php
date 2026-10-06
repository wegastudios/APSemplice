<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Anni solari della contabilità: si creano, si chiudono e si riaprono a mano.
 * Si può incassare, spendere e girare denaro solo in un anno solare aperto. Un anno non si chiude finché ha fondi accantonati non rimborsati.
 */
final class FiscalYears {

	private static function db(): \wpdb {
		return Db::db();
	}

	/** @return array[] anno, status (open|closed), created_at, closed_at — dal più recente */
	public static function all(): array {
		return self::db()->get_results( 'SELECT * FROM ' . Db::t( 'fiscal_years' ) . ' ORDER BY year DESC', ARRAY_A ) ?: array();
	}

	public static function get( int $year ): ?array {
		$row = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'fiscal_years' ) . ' WHERE year = %d', $year ), ARRAY_A );
		return $row ?: null;
	}

	/** L'anno solare più recente tra quelli creati (aperto o chiuso). */
	public static function latest(): ?int {
		$y = self::db()->get_var( 'SELECT MAX(year) FROM ' . Db::t( 'fiscal_years' ) );
		return null === $y ? null : (int) $y;
	}

	public static function is_open( int $year ): bool {
		$row = self::get( $year );
		return $row && 'open' === $row['status'];
	}

	public static function create( int $year ): void {
		if ( $year < 2000 || $year > 2100 ) {
			throw new \InvalidArgumentException( 'Anno non valido.' );
		}
		if ( self::get( $year ) ) {
			throw new \InvalidArgumentException( 'L\'anno solare ' . $year . ' esiste già.' );
		}
		self::db()->insert( Db::t( 'fiscal_years' ), array( 'year' => $year, 'status' => 'open', 'created_at' => Db::now() ) );
		Audit::log( 'year.created', 'year', $year );
	}

	/** Crea l'anno se manca (aperto). Un anno chiuso resta chiuso. */
	public static function ensure( int $year ): void {
		if ( ! self::get( $year ) ) {
			self::create( $year );
		}
	}

	/** Si può registrare un movimento a questa data? Solo se l'anno solare esiste ed è aperto. */
	public static function assert_open_for_date( string $date ): void {
		$year = (int) substr( $date, 0, 4 );
		$row  = self::get( $year );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'L\'anno solare ' . $year . ' non è stato creato: crealo da Contabilità › Anni solari prima di registrare movimenti in quella data.' );
		}
		if ( 'open' !== $row['status'] ) {
			throw new \InvalidArgumentException( 'L\'anno solare ' . $year . ' è chiuso: non accetta più incassi né spese. Riaprilo da Contabilità › Anni solari se devi correggerlo.' );
		}
	}

	/** Motivi per cui l'anno non si può chiudere (vuoto = si può). @return string[] */
	public static function blockers( int $year ): array {
		$out   = array();
		$funds = Plugin::funds()->unsettled_until_year( $year );
		if ( $funds > 0 ) {
			$out[] = 'ci sono fondi accantonati non ancora rimborsati (' . Money::format( $funds ) . '): rimborsali o liberali prima di chiudere l\'anno';
		}
		return $out;
	}

	public static function close( int $year ): void {
		$row = self::get( $year );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Anno solare non trovato.' );
		}
		if ( 'open' !== $row['status'] ) {
			throw new \InvalidArgumentException( 'L\'anno ' . $year . ' è già chiuso.' );
		}
		$b = self::blockers( $year );
		if ( $b ) {
			throw new \InvalidArgumentException( 'L\'anno ' . $year . ' non si può chiudere: ' . implode( '; ', $b ) . '.' );
		}
		self::db()->update( Db::t( 'fiscal_years' ), array( 'status' => 'closed', 'closed_at' => Db::now() ), array( 'year' => $year ) );
		Audit::log( 'year.closed', 'year', $year );
	}

	public static function reopen( int $year ): void {
		$row = self::get( $year );
		if ( ! $row || 'closed' !== $row['status'] ) {
			throw new \InvalidArgumentException( 'L\'anno solare non è chiuso.' );
		}
		self::db()->update( Db::t( 'fiscal_years' ), array( 'status' => 'open', 'closed_at' => null ), array( 'year' => $year ) );
		Audit::log( 'year.reopened', 'year', $year );
	}

	/** Numero di movimenti registrati in un anno (non annullati). */
	public static function movements( int $year ): int {
		return (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . ' WHERE voided_at IS NULL AND tx_date BETWEEN %s AND %s', $year . '-01-01', $year . '-12-31' ) );
	}

	/** Crea gli anni che servono: quello in corso e tutti quelli in cui ci sono già movimenti (per chi aggiorna da una versione senza anni). */
	public static function seed(): void {
		self::ensure( (int) substr( Db::today(), 0, 4 ) );
		foreach ( self::db()->get_col( 'SELECT DISTINCT YEAR(tx_date) FROM ' . Db::t( 'transactions' ) ) ?: array() as $y ) {
			if ( (int) $y >= 2000 && (int) $y <= 2100 ) {
				self::ensure( (int) $y );
			}
		}
	}
}
