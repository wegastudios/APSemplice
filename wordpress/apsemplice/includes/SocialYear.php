<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Anno sociale (es. 1 set 2025 - 31 ago 2026), distinto dall'anno solare usato per la
 * contabilità legale. Il mese di inizio è configurabile. I mesi sono stringhe "YYYY-MM"
 * (si confrontano correttamente come testo).
 */
final class SocialYear {

	/** @var int */
	public $start_year;
	/** @var int */
	public $start_month;

	public function __construct( int $start_year, int $start_month ) {
		$this->start_year  = $start_year;
		$this->start_month = $start_month;
	}

	public static function for_date( $date, int $start_month ): self {
		$d = $date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable( (string) $date );
		$y = (int) $d->format( 'Y' );
		$m = (int) $d->format( 'n' );
		return new self( $m >= $start_month ? $y : $y - 1, $start_month );
	}

	public static function from_label( string $label, int $start_month ): self {
			// Le etichette senza barra ("2026") sono anni solari, ad esempio gli anni delle tessere
		return new self( (int) substr( $label, 0, 4 ), false === strpos( $label, '/' ) ? 1 : $start_month );
	}

	public function label(): string {
		return 1 === $this->start_month ? (string) $this->start_year : $this->start_year . '/' . ( $this->start_year + 1 );
	}

	public function start(): \DateTimeImmutable {
		return new \DateTimeImmutable( sprintf( '%04d-%02d-01', $this->start_year, $this->start_month ) );
	}

	public function end(): \DateTimeImmutable {
		return $this->start()->modify( '+1 year' )->modify( '-1 day' );
	}

	/** @return string[] i 12 mesi "YYYY-MM" dell'anno sociale, in ordine. */
	public function months(): array {
		$out   = array();
		$start = $this->start();
		for ( $i = 0; $i < 12; $i++ ) {
			$out[] = $start->modify( '+' . $i . ' month' )->format( 'Y-m' );
		}
		return $out;
	}

	public function previous(): self {
		return new self( $this->start_year - 1, $this->start_month );
	}

	public function next(): self {
		return new self( $this->start_year + 1, $this->start_month );
	}

	/** Porta un mese dentro i limiti dell'anno sociale. */
	public function clamp( string $month ): string {
		$months = $this->months();
		if ( $month < $months[0] ) {
			return $months[0];
		}
		if ( $month > $months[11] ) {
			return $months[11];
		}
		return $month;
	}
}
