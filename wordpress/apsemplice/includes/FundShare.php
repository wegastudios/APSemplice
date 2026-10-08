<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/** Quota di un pagamento che va accantonata nel fondo del rimborso (importo fisso o percentuale), mai oltre il pagamento. */
final class FundShare {

	const NONE    = '';
	const FIXED   = 'fixed';
	const PERCENT = 'percent';

	public static function modes(): array {
		return array( self::NONE => 'Nessuna quota', self::FIXED => 'Importo fisso per ogni pagamento', self::PERCENT => 'Percentuale di ogni pagamento' );
	}

	/**
	 * @param string $mode  '', fixed (value = centesimi) o percent (value = centesimi di punto percentuale: 2550 = 25,50%)
	 * @return int centesimi da accantonare per un pagamento di $line_cents
	 */
	public static function cents( string $mode, int $value, int $line_cents ): int {
		if ( $line_cents <= 0 || $value <= 0 ) {
			return 0;
		}
		if ( self::FIXED === $mode ) {
			return min( $value, $line_cents );
		}
		if ( self::PERCENT === $mode ) {
			return (int) min( $line_cents, intdiv( $line_cents * min( $value, 10000 ), 10000 ) );
		}
		return 0;
	}

	/** Errori di una configurazione (vuoto se valida). @return string[] */
	public static function validate( string $mode, int $value, bool $has_volunteer ): array {
		if ( self::NONE === $mode ) {
			return array();
		}
		$errors = array();
		if ( ! in_array( $mode, array( self::FIXED, self::PERCENT ), true ) ) {
			$errors[] = 'Quota per il rimborso non valida.';
		} elseif ( $value <= 0 ) {
			$errors[] = 'Indica la quota per il rimborso (importo o percentuale maggiori di zero).';
		} elseif ( self::PERCENT === $mode && $value > 10000 ) {
			$errors[] = 'La percentuale per il rimborso non può superare il 100%.';
		}
		if ( ! $has_volunteer ) {
			$errors[] = 'Per accantonare una quota di rimborso indica il referente (il volontario da rimborsare).';
		}
		return $errors;
	}

	/** Testo leggibile: "25% di ogni pagamento", "2,00 € per ogni pagamento". */
	public static function label( string $mode, int $value ): string {
		if ( self::FIXED === $mode ) {
			return Money::format( $value ) . ' per ogni pagamento';
		}
		if ( self::PERCENT === $mode ) {
			return rtrim( rtrim( number_format( $value / 100, 2, ',', '' ), '0' ), ',' ) . '% di ogni pagamento';
		}
		return '';
	}
}
