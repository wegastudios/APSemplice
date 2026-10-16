<?php
namespace Portale;

/** Cifratura delle chiavi di licenza nel database (libsodium secretbox). */
final class Secrets {

	private static function key(): string {
		$k = base64_decode( (string) Config::get( 'app_key', '' ), true );
		if ( false === $k || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $k ) ) {
			throw new \RuntimeException( 'app_key mancante o non valida (servono 32 byte in base64).' );
		}
		return $k;
	}

	public static function seal( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	public static function open( string $sealed ): string {
		$raw = base64_decode( $sealed, true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			throw new \RuntimeException( 'Dato cifrato non valido.' );
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$out   = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, self::key() );
		if ( false === $out ) {
			throw new \RuntimeException( 'Impossibile decifrare.' );
		}
		return $out;
	}
}
