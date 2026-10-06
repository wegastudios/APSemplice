<?php
use ApSemplice\WebPush as W;
use PHPUnit\Framework\TestCase;

final class WebPushTest extends TestCase {

	protected function setUp(): void {
		if ( ! W::supported() ) {
			$this->markTestSkipped( 'openssl non disponibile' );
		}
	}

	/** Esempio dell'appendice A di RFC 8291. */
	public function test_rfc8291_example(): void {
		$d      = W::unb64u( 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw' );
		$as_pub = W::unb64u( 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8' );
		$ua_pub = W::unb64u( 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4' );
		$auth   = W::unb64u( 'BTBZMqHH6r4Tts7J_aSIgg' );
		$salt   = W::unb64u( 'DGv6ra1nlYgDCS1FRnbzlw' );
		$plain  = 'When I grow up, I want to be a watermelon';
		$p      = W::parts( $plain, $ua_pub, $auth, array( 'pem' => W::private_pem( $d, $as_pub ), 'public' => $as_pub ), $salt );
		$this->assertSame( 'kyrL1jIIOHEzg3sM2ZWRHDRB62YACZhhSlknJ672kSs', W::b64u( $p['ecdh'] ), 'segreto condiviso ECDH' );
		$this->assertSame( 'Snr3JMxaHVDXHWJn5wdC52WjpCtd2EIEGBykDcZW32k', W::b64u( $p['prk_key'] ), 'PRK_key' );
		$this->assertSame( 'S4lYMb_L0FxCeq0WhDx813KgSYqU26kOyzWUdsXYyrg', W::b64u( $p['ikm'] ), 'IKM' );
		$this->assertSame( 'oIhVW04MRdy2XN9CiKLxTg', W::b64u( $p['cek'] ), 'chiave di cifratura' );
		$this->assertSame( '4h_95klXJ5E_qnoN', W::b64u( $p['nonce'] ), 'nonce' );
		$this->assertSame( 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8', W::b64u( $p['header'] ), 'intestazione' );
		$ct = $p['ciphertext'];
		$this->assertSame( strlen( $plain ) + 1 + 16, strlen( $ct ), 'testo, delimitatore e tag' );
		$this->assertSame( '8pfeW0KbunFT06S', substr( W::b64u( $ct ), 0, 15 ), 'inizio del testo cifrato come nell\'RFC' );
		$this->assertSame( $plain . "\x02", openssl_decrypt( substr( $ct, 0, -16 ), 'aes-128-gcm', $p['cek'], OPENSSL_RAW_DATA, $p['nonce'], substr( $ct, -16 ) ), 'si decifra con chiave e nonce dell\'RFC' );
	}

	/** Cifra con chiavi nuove e decifra come farebbe il browser. */
	public function test_round_trip(): void {
		$ua   = W::new_keypair();
		$auth = random_bytes( 16 );
		$body = W::encrypt( '{"title":"Ciao","body":"Prova àèì"}', $ua['public'], $auth );
		$salt = substr( $body, 0, 16 );
		$this->assertSame( 4096, unpack( 'N', substr( $body, 16, 4 ) )[1] );
		$this->assertSame( 65, ord( $body[20] ) );
		$as_pub = substr( $body, 21, 65 );
		$ct     = substr( $body, 86 );
		$ecdh   = openssl_pkey_derive( openssl_pkey_get_public( W::public_pem( $as_pub ) ), openssl_pkey_get_private( $ua['pem'] ), 32 );
		$prk_k  = hash_hmac( 'sha256', $ecdh, $auth, true );
		$ikm    = substr( hash_hmac( 'sha256', "WebPush: info\0" . $ua['public'] . $as_pub . "\x01", $prk_k, true ), 0, 32 );
		$prk    = hash_hmac( 'sha256', $ikm, $salt, true );
		$cek    = substr( hash_hmac( 'sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true ), 0, 16 );
		$nonce  = substr( hash_hmac( 'sha256', "Content-Encoding: nonce\0\x01", $prk, true ), 0, 12 );
		$plain  = openssl_decrypt( substr( $ct, 0, -16 ), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr( $ct, -16 ) );
		$this->assertSame( '{"title":"Ciao","body":"Prova àèì"}' . "\x02", $plain );
	}

	public function test_bad_keys_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		W::encrypt( 'x', 'corta', 'auth-troppo-corta' );
	}

	public function test_vapid_signature_verifies(): void {
		$k   = W::new_keypair();
		$jwt = W::jwt( 'https://fcm.googleapis.com', 'mailto:info@example.org', $k['pem'], 2000000000 );
		list( $h, $c, $s ) = explode( '.', $jwt );
		$this->assertSame( array( 'typ' => 'JWT', 'alg' => 'ES256' ), json_decode( W::unb64u( $h ), true ) );
		$claims = json_decode( W::unb64u( $c ), true );
		$this->assertSame( 'https://fcm.googleapis.com', $claims['aud'] );
		$this->assertSame( 2000000000, $claims['exp'] );
		$this->assertSame( 64, strlen( W::unb64u( $s ) ) );
		$this->assertSame( 1, openssl_verify( $h . '.' . $c, W::raw_to_der( W::unb64u( $s ) ), W::public_pem( $k['public'] ), OPENSSL_ALGO_SHA256 ) );
		$this->assertSame( 0, openssl_verify( $h . '.' . $c . 'x', W::raw_to_der( W::unb64u( $s ) ), W::public_pem( $k['public'] ), OPENSSL_ALGO_SHA256 ) );
	}

	public function test_der_raw_conversion_round_trip(): void {
		$raw = str_repeat( "\x80", 32 ) . str_repeat( "\x01", 31 ) . "\x00";
		$this->assertSame( $raw, W::der_to_raw( W::raw_to_der( $raw ) ) );
	}

	public function test_only_known_push_services_are_allowed(): void {
		$this->assertTrue( W::allowed_endpoint( 'https://fcm.googleapis.com/fcm/send/abc' ) );
		$this->assertTrue( W::allowed_endpoint( 'https://updates.push.services.mozilla.com/wpush/v2/abc' ) );
		$this->assertTrue( W::allowed_endpoint( 'https://web.push.apple.com/abc' ) );
		$this->assertTrue( W::allowed_endpoint( 'https://wns2-par02p.notify.windows.com/w/?token=x' ) );
		$this->assertFalse( W::allowed_endpoint( 'http://fcm.googleapis.com/x' ), 'solo https' );
		$this->assertFalse( W::allowed_endpoint( 'https://evil.example.com/x' ) );
		$this->assertFalse( W::allowed_endpoint( 'https://fcm.googleapis.com.evil.com/x' ) );
		$this->assertFalse( W::allowed_endpoint( 'https://evilgoogleapis.com/x' ) );
		$this->assertFalse( W::allowed_endpoint( 'https://127.0.0.1/x' ) );
		$this->assertFalse( W::allowed_endpoint( 'https://user:pw@fcm.googleapis.com/x' ) );
		$this->assertFalse( W::allowed_endpoint( 'https://fcm.googleapis.com:8443/x' ) );
		$this->assertFalse( W::allowed_endpoint( 'javascript:alert(1)' ) );
	}
}
