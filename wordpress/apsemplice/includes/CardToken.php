<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Codice di verifica della tessera, stampato nel QR: "ID.firma". La firma (HMAC) non si può indovinare né costruire senza il segreto
 * del sito, quindi dal numero della tessera non si risale a quella di un altro socio. Non contiene dati personali.
 */
final class CardToken {

	public static function make( int $person_id, string $secret ): string {
		return substr( hash_hmac( 'sha256', 'card:' . $person_id, $secret ), 0, 20 );
	}

	public static function param( int $person_id, string $secret ): string {
		return $person_id . '.' . self::make( $person_id, $secret );
	}

	public static function valid( int $person_id, string $token, string $secret ): bool {
		return '' !== $token && hash_equals( self::make( $person_id, $secret ), $token );
	}

	/** @return array|null [id, firma] oppure null se il formato non è giusto */
	public static function parse( string $value ): ?array {
		if ( ! preg_match( '/^(\d{1,9})\.([a-f0-9]{20})$/', trim( $value ), $m ) ) {
			return null;
		}
		return array( (int) $m[1], $m[2] );
	}
}
