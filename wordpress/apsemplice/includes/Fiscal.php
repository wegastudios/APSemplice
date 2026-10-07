<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Dati fiscali dell'ente e calcolo dell'IVA (livello minimo: il commercialista completa i quadri).
 * Gli importi sono in centesimi. L'IVA si gestisce solo se l'ente ha la partita IVA e non è in regime forfettario.
 */
final class Fiscal {

	const ORDINARY   = 'ordinario';
	const FLAT       = 'forfettario';
	const L398       = 'legge_398';
	const OTHER      = 'altro';

	/** Prezzi indicati IVA compresa o IVA esclusa. */
	const INCLUDED = 'incl';
	const EXCLUDED = 'escl';

	/** Aliquote proponibili (percentuali intere). */
	const RATES = array( 22, 10, 5, 4, 0 );

	public static function regimes(): array {
		return array(
			self::ORDINARY => 'Regime ordinario',
			self::L398     => 'Legge 398/1991 (associazioni sportive e simili)',
			self::FLAT     => 'Regime forfettario (senza IVA)',
			self::OTHER    => 'Altro regime',
		);
	}

	/** Partita IVA: 11 cifre con cifra di controllo (si accetta anche il prefisso IT e gli spazi). */
	public static function normalize_vat( string $v ): string {
		$v = strtoupper( preg_replace( '/[\s.\-]+/', '', $v ) );
		return 0 === strpos( $v, 'IT' ) ? substr( $v, 2 ) : $v;
	}

	public static function is_valid_vat( string $v ): bool {
		$v = self::normalize_vat( $v );
		if ( ! preg_match( '/^\d{11}$/', $v ) || '00000000000' === $v ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$d = (int) $v[ $i ];
			if ( 1 === $i % 2 ) {
				$d *= 2;
				if ( $d > 9 ) {
					$d -= 9;
				}
			}
			$sum += $d;
		}
		return ( ( 10 - $sum % 10 ) % 10 ) === (int) $v[10];
	}

	/** Codice fiscale di un ente: 11 cifre (con cifra di controllo) oppure, se è una persona fisica, il codice a 16 caratteri. */
	public static function is_valid_entity_tax_code( string $s ): bool {
		$s = TaxCode::normalize( $s );
		if ( preg_match( '/^\d{11}$/', $s ) ) {
			return self::is_valid_vat( $s );
		}
		return 16 === strlen( $s ) && TaxCode::is_valid( $s );
	}

	public static function is_rate( $rate ): bool {
		return is_numeric( $rate ) && (int) $rate >= 0 && (int) $rate <= 30;
	}

	/** L'ente applica l'IVA? Solo con la partita IVA e fuori dal regime forfettario. */
	public static function vat_applies(): bool {
		return ! empty( Settings::get( 'has_vat' ) ) && self::FLAT !== (string) Settings::get( 'fiscal_regime' );
	}

	public static function default_rate(): int {
		return max( 0, min( 30, (int) Settings::get( 'vat_default_rate' ) ) );
	}

	/** Le voci si inseriscono di default IVA compresa o esclusa? */
	public static function default_mode(): string {
		return self::EXCLUDED === (string) Settings::get( 'vat_prices_mode' ) ? self::EXCLUDED : self::INCLUDED;
	}

	/** Aliquota scelta in un modulo: vuota o «none» = fuori campo IVA (null), altrimenti un numero da 0 a 30. */
	public static function clean_rate( $raw ): ?int {
		if ( null === $raw || '' === trim( (string) $raw ) || 'none' === $raw ) {
			return null;
		}
		return self::is_rate( $raw ) ? (int) $raw : null;
	}

	/** Aliquota delle quote associative (null = fuori campo IVA, come di norma per le quote dei soci). */
	public static function membership_rate(): ?int {
		return self::clean_rate( Settings::get( 'vat_membership_rate' ) );
	}

	/** Aliquota di un'attività (null = fuori campo IVA). */
	public static function activity_rate( ?array $activity ): ?int {
		return $activity ? self::clean_rate( $activity['vat_rate'] ?? null ) : null;
	}

	/** IVA contenuta in un importo lordo: 0 se l'ente non applica l'IVA o la voce è fuori campo. */
	public static function vat_of( int $gross_cents, ?int $rate ): int {
		if ( null === $rate || ! self::vat_applies() ) {
			return 0;
		}
		return self::split( $gross_cents, $rate, true )['vat'];
	}

	/** Opzioni per la scelta dell'aliquota: fuori campo + le aliquote proponibili. */
	public static function rate_options(): array {
		$out = array( 'none' => 'Fuori campo IVA' );
		foreach ( self::RATES as $r ) {
			$out[ (string) $r ] = $r . '%';
		}
		return $out;
	}

	/**
	 * Importo inserito in un modulo => importo lordo (quello che si incassa o si paga).
	 * Se l'importo è indicato IVA esclusa si aggiunge l'IVA dell'aliquota scelta; senza aliquota o senza IVA resta com'è.
	 */
	public static function gross_from_input( int $cents, ?int $rate, string $mode ): int {
		if ( self::EXCLUDED !== $mode || null === $rate || ! self::vat_applies() ) {
			return $cents;
		}
		return self::split( $cents, $rate, false )['gross'];
	}

	/**
	 * Scompone un importo.
	 *
	 * @param int  $amount_cents importo indicato
	 * @param int  $rate         aliquota in percentuale
	 * @param bool $includes_vat true se l'importo è IVA compresa
	 * @return array{net:int,vat:int,gross:int}
	 */
	public static function split( int $amount_cents, int $rate, bool $includes_vat ): array {
		$rate = max( 0, $rate );
		if ( $includes_vat ) {
			$net = (int) round( $amount_cents * 100 / ( 100 + $rate ) );
			return array( 'net' => $net, 'vat' => $amount_cents - $net, 'gross' => $amount_cents );
		}
		$vat = (int) round( $amount_cents * $rate / 100 );
		return array( 'net' => $amount_cents, 'vat' => $vat, 'gross' => $amount_cents + $vat );
	}
}
