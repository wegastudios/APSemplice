<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Link di attivazione per un socio registrato senza email: "ID.SCADENZA.firma". Chi ha il link (mandato su WhatsApp)
 * sceglie la propria email e la password e così attiva l'accesso all'area riservata. La firma non si può costruire senza il
 * segreto del sito; il link scade e, una volta usato, non serve più (il socio ha già un utente).
 */
final class ActivationToken {

	const VALID_DAYS = 30;

	public static function make( int $person_id, int $expires, string $secret ): string {
		return substr( hash_hmac( 'sha256', 'activate:' . $person_id . ':' . $expires, $secret ), 0, 24 );
	}

	public static function param( int $person_id, int $expires, string $secret ): string {
		return $person_id . '.' . $expires . '.' . self::make( $person_id, $expires, $secret );
	}

	/** @return array|null [persona, scadenza, firma] */
	public static function parse( string $value ): ?array {
		if ( ! preg_match( '/^(\d{1,9})\.(\d{9,11})\.([a-f0-9]{24})$/', trim( $value ), $m ) ) {
			return null;
		}
		return array( (int) $m[1], (int) $m[2], $m[3] );
	}

	/** @return string ok | invalid | expired */
	public static function check( string $value, string $secret, int $now ): string {
		$p = self::parse( $value );
		if ( ! $p || ! hash_equals( self::make( $p[0], $p[1], $secret ), $p[2] ) ) {
			return 'invalid';
		}
		return $p[1] >= $now ? 'ok' : 'expired';
	}
}
