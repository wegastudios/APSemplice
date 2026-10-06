<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Quando si può annullare una prenotazione a un evento, e quando solo cambiare nominativo.
 *
 *  - evento GRATUITO (per quella persona): si può sempre annullare, fino all'inizio dell'evento;
 *  - evento A PAGAMENTO: non si annulla mai, a meno che l'evento sia stato creato come "cancellabile":
 *    allora fino al termine scelto (24 ore, 48 ore, una settimana prima dell'inizio);
 *  - il CAMBIO DI NOMINATIVO è sempre possibile fino all'inizio dell'evento, anche per gli eventi a pagamento
 *    non cancellabili (se il nuovo partecipante paga un contributo maggiore, ad esempio un ospite, si integra la differenza).
 *
 * L'inizio è la data con l'orario dell'evento; senza orario si considera la mezzanotte di quel giorno.
 * Gli amministratori possono sempre annullare (con eventuale rimborso a mano): la regola vale per i soci dal sito.
 */
final class CancelPolicy {

	const H24 = '24h';
	const H48 = '48h';
	const D7  = '7d';

	const REASON_FREE           = 'free';
	const REASON_OK             = 'ok';
	const REASON_NOT_CANCELLABLE = 'not_cancellable';
	const REASON_DEADLINE       = 'deadline_passed';
	const REASON_STARTED        = 'started';

	public static function labels(): array {
		return array( self::H24 => '24 ore prima', self::H48 => '48 ore prima', self::D7 => 'Una settimana prima' );
	}

	public static function is_valid( ?string $policy ): bool {
		return null !== $policy && isset( self::labels()[ $policy ] );
	}

	public static function hours( string $policy ): int {
		$h = array( self::H24 => 24, self::H48 => 48, self::D7 => 168 );
		return $h[ $policy ] ?? 48;
	}

	/** Inizio dell'evento (senza orario: mezzanotte). */
	public static function start( string $date, ?string $time, \DateTimeZone $tz ): \DateTimeImmutable {
		return new \DateTimeImmutable( $date . ' ' . ( $time ? $time : '00:00' ) . ':00', $tz );
	}

	/** Ultimo istante utile per annullare. */
	public static function deadline( string $date, ?string $time, string $policy, \DateTimeZone $tz ): \DateTimeImmutable {
		return self::start( $date, $time, $tz )->modify( '-' . self::hours( $policy ) . ' hours' );
	}

	/**
	 * @param int         $fee_due_cents  contributo dovuto da QUESTA prenotazione (0 = gratuita)
	 * @param bool        $cancellable    l'evento è stato creato come cancellabile
	 * @param string|null $policy         termine scelto per l'evento (null = quello predefinito)
	 * @param string      $default_policy termine predefinito del plugin
	 * @return array ['allowed'=>bool, 'reason'=>REASON_*, 'deadline'=>?\DateTimeImmutable]
	 */
	public static function evaluate( int $fee_due_cents, bool $cancellable, ?string $policy, string $default_policy, string $date, ?string $time, \DateTimeImmutable $now ): array {
		$start = self::start( $date, $time, $now->getTimezone() );
		if ( $now >= $start ) {
			return array( 'allowed' => false, 'reason' => self::REASON_STARTED, 'deadline' => null );
		}
		if ( $fee_due_cents <= 0 ) {
			return array( 'allowed' => true, 'reason' => self::REASON_FREE, 'deadline' => null );
		}
		if ( ! $cancellable ) {
			return array( 'allowed' => false, 'reason' => self::REASON_NOT_CANCELLABLE, 'deadline' => null );
		}
		$chosen   = self::is_valid( $policy ) ? (string) $policy : ( self::is_valid( $default_policy ) ? $default_policy : self::H48 );
		$deadline = self::deadline( $date, $time, $chosen, $now->getTimezone() );
		return array(
			'allowed'  => $now <= $deadline,
			'reason'   => $now <= $deadline ? self::REASON_OK : self::REASON_DEADLINE,
			'deadline' => $deadline,
		);
	}

	/** Il nominativo si può cambiare finché l'evento non è iniziato. */
	public static function can_transfer( string $date, ?string $time, \DateTimeImmutable $now ): bool {
		return $now < self::start( $date, $time, $now->getTimezone() );
	}

	/** Spiegazione per l'utente. */
	public static function message( array $eval ): string {
		switch ( $eval['reason'] ) {
			case self::REASON_STARTED:
				return 'L\'evento è già iniziato.';
			case self::REASON_NOT_CANCELLABLE:
				return 'Questo evento è a pagamento e non è cancellabile. Puoi però cambiare il nominativo.';
			case self::REASON_DEADLINE:
				return 'Il termine per annullare è scaduto' . ( $eval['deadline'] ? ' (' . $eval['deadline']->format( 'd/m/Y H:i' ) . ')' : '' ) . '. Puoi però cambiare il nominativo.';
			case self::REASON_OK:
				return 'Puoi annullare fino al ' . ( $eval['deadline'] ? $eval['deadline']->format( 'd/m/Y H:i' ) : '' ) . '.';
		}
		return 'Evento gratuito: puoi annullare quando vuoi.';
	}
}
