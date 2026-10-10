<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Cifratura delle chiavi dei gateway (Stripe, PayPal) nel database.
 * La chiave di cifratura deriva dai "salt" di questo sito (gestiti da WordPress, nessuna configurazione a mano): in un dump del
 * database, in un backup o in un'esportazione delle opzioni le chiavi non compaiono in chiaro. Copiando il database su un altro
 * sito (es. staging) le chiavi salvate non sono leggibili lì: è voluto, così lo staging non usa le chiavi reali dei pagamenti.
 */
final class Secrets {

	const PREFIX = 'enc1:';

	private static function key( string $material ): string {
		return hash( 'sha256', 'associazionesemplice|' . $material, true ); // 32 byte
	}

	public static function is_encrypted( string $value ): bool {
		return 0 === strpos( $value, self::PREFIX );
	}

	/** @param string $material segreto da cui derivare la chiave (in WordPress: wp_salt('auth')) */
	public static function encrypt( string $plain, string $material ): string {
		if ( '' === $plain ) {
			return '';
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key( $material ) );
		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	/** @return string|null il testo in chiaro; null se vuoto, manomesso o cifrato con un'altra chiave */
	public static function decrypt( string $value, string $material ): ?string {
		if ( '' === $value || ! self::is_encrypted( $value ) ) {
			return null;
		}
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key( $material ) );
		return false === $plain ? null : $plain;
	}

	/** "sk_test_51Habcdef" => "••••cdef" (per mostrare che una chiave c'è, senza rivelarla). */
	public static function mask( string $plain ): string {
		return '' === $plain ? '' : '••••' . substr( $plain, -4 );
	}
}
