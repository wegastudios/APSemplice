<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Notifiche Web Push senza librerie esterne: cifratura del messaggio (RFC 8291, aes128gcm / RFC 8188), firma VAPID (RFC 8292, ES256) e invio.
 * Serve l'estensione openssl di PHP (curva P-256, ECDH, AES-GCM).
 */
final class WebPush {

	/** @var callable|null prova: function ( string $url, array $args ): array{code:int} al posto della richiesta vera */
	public static $http = null;

	const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
	const PRIV_PREFIX = '30770201010420';
	const PRIV_MIDDLE = 'a00a06082a8648ce3d030107a144034200';

	/** Servizi di push riconosciuti: l'indirizzo di una sottoscrizione deve stare qui (evita richieste verso indirizzi arbitrari). */
	const HOST_SUFFIXES = array( 'googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com', 'push.services.mozilla.org' );

	public static function supported(): bool {
		return function_exists( 'openssl_pkey_derive' ) && function_exists( 'openssl_pkey_new' ) && in_array( 'aes-128-gcm', openssl_get_cipher_methods(), true );
	}

	public static function b64u( string $s ): string {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}

	public static function unb64u( string $s ): string {
		return (string) base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
	}

	/** L'indirizzo è https e di un servizio di push noto? */
	public static function allowed_endpoint( string $url ): bool {
		$p = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- lettura/scrittura in streaming di file grandi
		if ( ! is_array( $p ) || ( $p['scheme'] ?? '' ) !== 'https' || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] ) ) {
			return false;
		}
		$host = strtolower( (string) $p['host'] );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) || strlen( $url ) > 600 ) {
			return false;
		}
		if ( isset( $p['port'] ) && 443 !== (int) $p['port'] ) {
			return false;
		}
		$suffixes = function_exists( 'apply_filters' ) ? (array) apply_filters( 'asem_push_hosts', self::HOST_SUFFIXES ) : self::HOST_SUFFIXES;
		foreach ( $suffixes as $s ) {
			if ( $host === $s || substr( $host, -strlen( $s ) - 1 ) === '.' . $s ) {
				return true;
			}
		}
		return false;
	}

	// ---------- Chiavi ----------

	/** Punto pubblico non compresso (65 byte) di una chiave EC. */
	public static function public_raw( $pkey ): string {
		$d = openssl_pkey_get_details( $pkey );
		return "\x04" . str_pad( (string) $d['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( (string) $d['ec']['y'], 32, "\0", STR_PAD_LEFT );
	}

	/** @return array{pem:string,public:string} nuova coppia P-256 */
	public static function new_keypair(): array {
		$res = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
		if ( ! $res ) {
			throw new \RuntimeException( 'Impossibile creare le chiavi (openssl).' );
		}
		openssl_pkey_export( $res, $pem );
		return array( 'pem' => (string) $pem, 'public' => self::public_raw( $res ) );
	}

	private static function pem( string $label, string $der ): string {
		return '-----BEGIN ' . $label . "-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . '-----END ' . $label . "-----\n";
	}

	public static function public_pem( string $public65 ): string {
		return self::pem( 'PUBLIC KEY', (string) hex2bin( self::SPKI_PREFIX ) . $public65 );
	}

	/** Chiave privata da valore grezzo (32 byte) e punto pubblico (65 byte). */
	public static function private_pem( string $d32, string $public65 ): string {
		return self::pem( 'EC PRIVATE KEY', (string) hex2bin( self::PRIV_PREFIX ) . $d32 . (string) hex2bin( self::PRIV_MIDDLE ) . $public65 );
	}

	// ---------- Cifratura (RFC 8291) ----------

	private static function hmac( string $key, string $data ): string {
		return hash_hmac( 'sha256', $data, $key, true );
	}

	private static function expand( string $prk, string $info, int $len ): string {
		return substr( self::hmac( $prk, $info . "\x01" ), 0, $len );
	}

	/**
	 * Tutti i passaggi della cifratura (utile ai test con i valori dell'RFC).
	 *
	 * @param array|null $eph  chiave temporanea del mittente [pem, public] (se manca ne crea una)
	 * @param string|null $salt 16 byte (se manca, casuale)
	 * @return array ecdh, prk_key, key_info, ikm, cek, nonce, header, ciphertext, body
	 */
	public static function parts( string $payload, string $ua_public, string $auth, ?array $eph = null, ?string $salt = null ): array {
		if ( 65 !== strlen( $ua_public ) || "\x04" !== $ua_public[0] || strlen( $auth ) < 16 ) {
			throw new \InvalidArgumentException( 'Chiavi della sottoscrizione non valide.' );
		}
		$eph   = $eph ?: self::new_keypair();
		$salt  = $salt ?? random_bytes( 16 );
		$peer  = openssl_pkey_get_public( self::public_pem( $ua_public ) );
		$mine  = openssl_pkey_get_private( $eph['pem'] );
		$ecdh  = $peer && $mine ? openssl_pkey_derive( $peer, $mine, 32 ) : false;
		if ( false === $ecdh ) {
			throw new \RuntimeException( 'Calcolo della chiave condivisa non riuscito.' );
		}
		$prk_key  = self::hmac( $auth, $ecdh );
		$key_info = "WebPush: info\0" . $ua_public . $eph['public'];
		$ikm      = self::expand( $prk_key, $key_info, 32 );
		$prk      = self::hmac( $salt, $ikm );
		$cek      = self::expand( $prk, "Content-Encoding: aes128gcm\0", 16 );
		$nonce    = self::expand( $prk, "Content-Encoding: nonce\0", 12 );
		$header   = $salt . pack( 'N', 4096 ) . chr( 65 ) . $eph['public'];
		$tag      = '';
		$cipher   = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
		if ( false === $cipher ) {
			throw new \RuntimeException( 'Cifratura non riuscita.' );
		}
		$ct = $cipher . $tag;
		return array( 'ecdh' => $ecdh, 'prk_key' => $prk_key, 'key_info' => $key_info, 'ikm' => $ikm, 'cek' => $cek, 'nonce' => $nonce, 'header' => $header, 'ciphertext' => $ct, 'body' => $header . $ct );
	}

	public static function encrypt( string $payload, string $ua_public, string $auth ): string {
		return self::parts( $payload, $ua_public, $auth )['body'];
	}

	// ---------- VAPID (RFC 8292) ----------

	/** Firma ECDSA da DER a R||S (64 byte). */
	public static function der_to_raw( string $der ): string {
		$o   = 2;
		$out = '';
		if ( "\x30" !== ( $der[0] ?? '' ) ) {
			return '';
		}
		if ( ord( $der[1] ) & 0x80 ) {
			$o += ord( $der[1] ) & 0x7f;
		}
		for ( $i = 0; $i < 2; $i++ ) {
			$len = ord( $der[ $o + 1 ] );
			$int = ltrim( substr( $der, $o + 2, $len ), "\0" );
			$out .= str_pad( $int, 32, "\0", STR_PAD_LEFT );
			$o   += 2 + $len;
		}
		return $out;
	}

	/** Firma ECDSA da R||S a DER (per verificare con openssl). */
	public static function raw_to_der( string $raw ): string {
		$enc = function ( string $i ): string {
			$i = ltrim( $i, "\0" );
			if ( '' === $i || ord( $i[0] ) & 0x80 ) {
				$i = "\0" . $i;
			}
			return "\x02" . chr( strlen( $i ) ) . $i;
		};
		$body = $enc( substr( $raw, 0, 32 ) ) . $enc( substr( $raw, 32, 32 ) );
		return "\x30" . chr( strlen( $body ) ) . $body;
	}

	public static function jwt( string $audience, string $subject, string $private_pem, ?int $exp = null ): string {
		$h = self::b64u( (string) json_encode( array( "typ" => "JWT", "alg" => "ES256" ) ) );
		$c = self::b64u( (string) json_encode( array( "aud" => $audience, "exp" => $exp ?? time() + 12 * 3600, "sub" => $subject ) ) );
		$der = '';
		if ( ! openssl_sign( $h . '.' . $c, $der, $private_pem, OPENSSL_ALGO_SHA256 ) ) {
			throw new \RuntimeException( 'Firma VAPID non riuscita.' );
		}
		return $h . '.' . $c . '.' . self::b64u( self::der_to_raw( $der ) );
	}

	// ---------- Invio ----------

	/**
	 * Manda un messaggio a una sottoscrizione.
	 *
	 * @param array $sub endpoint, p256dh, auth (base64url)
	 * @return array{ok:bool,gone:bool,code:int}
	 */
	public static function send( array $sub, string $payload, string $vapid_public_b64u, string $vapid_private_pem, string $subject, int $ttl = 86400 ): array {
		$p   = parse_url( (string) $sub['endpoint'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- lettura/scrittura in streaming di file grandi
		$aud = ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' );
		$jwt = self::jwt( $aud, $subject, $vapid_private_pem );
		$args = array(
			'timeout'     => 5,
			'redirection' => 0,
			'headers'     => array(
				'Content-Encoding' => 'aes128gcm',
				'Content-Type'     => 'application/octet-stream',
				'TTL'              => (string) $ttl,
				'Urgency'          => 'normal',
				'Authorization'    => 'vapid t=' . $jwt . ', k=' . $vapid_public_b64u,
			),
			'body'        => self::encrypt( $payload, self::unb64u( (string) $sub['p256dh'] ), self::unb64u( (string) $sub['auth'] ) ),
		);
		if ( null !== self::$http ) {
			$res  = call_user_func( self::$http, (string) $sub['endpoint'], $args );
			$code = (int) ( $res['code'] ?? 0 );
		} else {
			$r    = wp_remote_post( (string) $sub['endpoint'], $args );
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		}
		return array( 'ok' => $code >= 200 && $code < 300, 'gone' => 404 === $code || 410 === $code, 'code' => $code );
	}
}
