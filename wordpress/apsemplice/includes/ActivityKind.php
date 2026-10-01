<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APS_TESTS' ) || exit;

/**
 * I tre tipi di attività. In tutti il contributo può essere nullo (attività gratuita) e per gli ospiti
 * (non soci) può essere diverso da quello dei soci.
 *
 *  - course     Corso: iscrizione per mesi, contributo mensile.
 *  - event      Evento una tantum: una data, prenotazione obbligatoria, un contributo.
 *  - recurring  Evento ricorrente: molte date, iscrizione obbligatoria al SINGOLO evento, contributo per evento.
 */
final class ActivityKind {
	const COURSE    = 'course';
	const EVENT     = 'event';
	const RECURRING = 'recurring';

	public static function labels(): array {
		return array(
			self::COURSE    => 'Corso (iscrizione mensile)',
			self::EVENT     => 'Evento una tantum (prenotazione obbligatoria)',
			self::RECURRING => 'Evento ricorrente (iscrizione al singolo evento)',
		);
	}

	public static function short_label( string $kind ): string {
		$m = array( self::COURSE => 'Corso', self::EVENT => 'Evento', self::RECURRING => 'Evento ricorrente' );
		return $m[ $kind ] ?? $kind;
	}

	public static function is_valid( string $kind ): bool {
		return isset( self::labels()[ $kind ] );
	}

	/** Eventi ed eventi ricorrenti hanno delle date (sessioni) a cui ci si prenota. */
	public static function uses_sessions( string $kind ): bool {
		return self::EVENT === $kind || self::RECURRING === $kind;
	}

	/** Il contributo si intende "al mese" per i corsi, "a evento" per gli altri. */
	public static function fee_unit( string $kind ): string {
		return self::COURSE === $kind ? 'al mese' : 'a evento';
	}
}
