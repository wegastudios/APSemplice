<?php
use ApSemplice\ApplePass;
use ApSemplice\GoogleWallet;
use ApSemplice\Png;
use ApSemplice\WalletCredentials;
use PHPUnit\Framework\TestCase;

final class WalletTest extends TestCase {

	protected function setUp(): void {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			$this->markTestSkipped( 'estensione openssl non disponibile' );
		}
	}

	/** @return array [certificato PEM, chiave PEM, risorsa certificato, risorsa chiave] */
	private function make_cert( string $cn ): array {
		$key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		$csr = openssl_csr_new( array( 'commonName' => $cn, 'organizationalUnitName' => 'TEAM123456' ), $key, array( 'digest_alg' => 'sha256' ) );
		$x   = openssl_csr_sign( $csr, null, $key, 365, array( 'digest_alg' => 'sha256' ) );
		openssl_x509_export( $x, $cert );
		openssl_pkey_export( $key, $keypem );
		return array( $cert, $keypem, $x, $key );
	}

	private function card( string $url = 'https://example.org/?apse_card=1.abc' ): array {
		return array(
			'id' => 12, 'serial' => 'apse-12', 'name' => 'Mario Rossi', 'type' => 'Socio ordinario', 'card_number' => '34', 'until_text' => '31/08/2026',
			'expires_iso' => '2026-08-31T23:59:59+02:00', 'org' => 'APS Prova', 'color' => '#2271b1', 'url' => $url, 'description' => 'Tessera associativa',
		);
	}

	// ---------- Png ----------

	public function test_png_is_a_valid_solid_image(): void {
		$png = Png::solid( 29, 29, 10, 20, 30 );
		$this->assertSame( "\x89PNG\r\n\x1a\n", substr( $png, 0, 8 ) );
		$pos    = 8;
		$idat   = '';
		$types  = array();
		while ( $pos < strlen( $png ) ) {
			$len  = unpack( 'N', substr( $png, $pos, 4 ) )[1];
			$type = substr( $png, $pos + 4, 4 );
			$data = substr( $png, $pos + 8, $len );
			$crc  = unpack( 'N', substr( $png, $pos + 8 + $len, 4 ) )[1];
			$this->assertSame( crc32( $type . $data ) & 0xFFFFFFFF, $crc, "CRC del blocco $type" );
			$types[] = $type;
			if ( 'IHDR' === $type ) {
				$this->assertSame( array( 29, 29, 8, 2 ), array_values( array( unpack( 'N', substr( $data, 0, 4 ) )[1], unpack( 'N', substr( $data, 4, 4 ) )[1], ord( $data[8] ), ord( $data[9] ) ) ) );
			}
			if ( 'IDAT' === $type ) {
				$idat .= $data;
			}
			$pos += 12 + $len;
		}
		$this->assertSame( array( 'IHDR', 'IDAT', 'IEND' ), $types );
		$raw = gzuncompress( $idat );
		$this->assertSame( 29 * ( 1 + 29 * 3 ), strlen( $raw ) );
		$this->assertSame( "\0" . chr( 10 ) . chr( 20 ) . chr( 30 ), substr( $raw, 0, 4 ) );
	}

	public function test_colors(): void {
		$this->assertSame( array( 34, 113, 177 ), Png::rgb( '#2271b1' ) );
		$this->assertSame( array( 34, 113, 177 ), Png::rgb( 'boh' ), 'colore non valido: blu predefinito' );
		$this->assertTrue( Png::is_light( array( 250, 250, 250 ) ) );
		$this->assertFalse( Png::is_light( array( 34, 113, 177 ) ) );
	}

	// ---------- Credenziali ----------

	public function test_der_is_converted_to_pem_and_p12_is_read(): void {
		list( $cert, $key, $x, $pk ) = $this->make_cert( 'Pass Type ID: pass.test.apse' );
		preg_match( '/-----BEGIN CERTIFICATE-----(.+)-----END CERTIFICATE-----/s', $cert, $m );
		$der = base64_decode( $m[1] );
		$pem = WalletCredentials::to_pem( $der );
		$this->assertSame( openssl_x509_fingerprint( $cert ), openssl_x509_fingerprint( $pem ), 'un .cer binario diventa PEM' );
		$this->assertStringContainsString( '-----BEGIN CERTIFICATE-----', WalletCredentials::to_pem( $cert ) );

		openssl_pkcs12_export( $x, $p12, $pk, 'pw123' );
		$r = WalletCredentials::from_p12( $p12, 'pw123' );
		$this->assertSame( openssl_x509_fingerprint( $cert ), openssl_x509_fingerprint( $r['cert'] ) );
		WalletCredentials::check_pair( $r['cert'], $r['key'] );
		$this->expectException( \InvalidArgumentException::class );
		WalletCredentials::from_p12( $p12, 'password sbagliata' );
	}

	public function test_key_must_match_the_certificate(): void {
		list( $cert ) = $this->make_cert( 'uno' );
		list( , $other_key ) = $this->make_cert( 'due' );
		$this->expectException( \InvalidArgumentException::class );
		WalletCredentials::check_pair( $cert, $other_key );
	}

	public function test_certificate_info_gives_pass_type_and_team(): void {
		list( $cert ) = $this->make_cert( 'Pass Type ID: pass.test.apse' );
		$i = WalletCredentials::cert_info( $cert );
		$this->assertSame( 'pass.test.apse', $i['pass_type'] );
		$this->assertSame( 'TEAM123456', $i['team'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $i['expires'] );
	}

	public function test_google_service_account_json(): void {
		list( , $key ) = $this->make_cert( 'g' );
		$g = WalletCredentials::google_from_json( json_encode( array( 'client_email' => 'wallet@progetto.iam.gserviceaccount.com', 'private_key' => $key ) ) );
		$this->assertSame( 'wallet@progetto.iam.gserviceaccount.com', $g['email'] );
		foreach ( array( '{}', 'non json', json_encode( array( 'client_email' => 'a@b.c', 'private_key' => 'non una chiave' ) ) ) as $bad ) {
			try {
				WalletCredentials::google_from_json( $bad );
				$this->fail( 'doveva rifiutare: ' . $bad );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	// ---------- Google Wallet ----------

	public function test_google_save_link_is_a_signed_jwt(): void {
		list( $cert, $key ) = $this->make_cert( 'g' );
		$cfg = array( 'issuer' => '3388000000012345678', 'email' => 'wallet@progetto.iam.gserviceaccount.com', 'key' => $key );
		$url = GoogleWallet::save_url( $cfg, $this->card(), 'https://example.org', 1700000000 );
		$this->assertStringStartsWith( 'https://pay.google.com/gp/v/save/', $url );
		$jwt = substr( $url, strlen( 'https://pay.google.com/gp/v/save/' ) );
		$this->assertLessThanOrEqual( GoogleWallet::MAX_JWT, strlen( $jwt ) );
		list( $h, $p, $s ) = explode( '.', $jwt );
		$this->assertSame( array( 'alg' => 'RS256', 'typ' => 'JWT' ), json_decode( GoogleWallet::b64url_decode( $h ), true ) );
		$this->assertSame( 1, openssl_verify( $h . '.' . $p, GoogleWallet::b64url_decode( $s ), openssl_pkey_get_public( $cert ), OPENSSL_ALGO_SHA256 ), 'la firma RS256 si verifica con la chiave pubblica' );
		$c = json_decode( GoogleWallet::b64url_decode( $p ), true );
		$this->assertSame( 'wallet@progetto.iam.gserviceaccount.com', $c['iss'] );
		$this->assertSame( 'google', $c['aud'] );
		$this->assertSame( 'savetowallet', $c['typ'] );
		$this->assertSame( array( 'https://example.org' ), $c['origins'] );
		$o = $c['payload']['genericObjects'][0];
		$this->assertSame( '3388000000012345678.apse_p12', $o['id'] );
		$this->assertSame( '3388000000012345678.apse_card', $o['classId'] );
		$this->assertSame( '3388000000012345678.apse_card', $c['payload']['genericClasses'][0]['id'] );
		$this->assertSame( 'QR_CODE', $o['barcode']['type'] );
		$this->assertSame( 'https://example.org/?apse_card=1.abc', $o['barcode']['value'] );
		$this->assertSame( 'Mario Rossi', $o['header']['defaultValue']['value'] );
		$this->assertSame( '#2271b1', $o['hexBackgroundColor'] );
	}

	public function test_google_link_without_qr_and_too_long_card(): void {
		list( , $key ) = $this->make_cert( 'g' );
		$cfg = array( 'issuer' => '3388000000012345678', 'email' => 'w@p.iam.gserviceaccount.com', 'key' => $key );
		$jwt = substr( GoogleWallet::save_url( $cfg, $this->card( '' ), 'https://example.org', 1 ), strlen( 'https://pay.google.com/gp/v/save/' ) );
		$o   = json_decode( GoogleWallet::b64url_decode( explode( '.', $jwt )[1] ), true )['payload']['genericObjects'][0];
		$this->assertArrayNotHasKey( 'barcode', $o, 'senza QR attivo non c\'è il codice' );
		$long         = $this->card();
		$long['name'] = str_repeat( 'Nome lunghissimo ', 60 );
		$this->expectException( \InvalidArgumentException::class );
		GoogleWallet::save_url( $cfg, $long, 'https://example.org', 1 );
	}

	public function test_google_invalid_inputs(): void {
		$this->expectException( \InvalidArgumentException::class );
		GoogleWallet::save_url( array( 'issuer' => 'abc', 'email' => 'x', 'key' => 'x' ), $this->card(), 'https://example.org', 1 );
	}

	// ---------- Apple Wallet ----------

	public function test_smime_signature_extraction(): void {
		$sig  = random_bytes( 300 );
		$mime = "MIME-Version: 1.0\nContent-Type: multipart/signed; boundary=\"----ABC\"\n\nThis is an S/MIME signed message\n\n------ABC\nContent-Type: application/octet-stream\n\nmanifest\n------ABC\n"
			. "Content-Type: application/x-pkcs7-signature; name=\"smime.p7s\"\nContent-Transfer-Encoding: base64\nContent-Disposition: attachment; filename=\"smime.p7s\"\n\n"
			. chunk_split( base64_encode( $sig ), 64, "\n" ) . "\n------ABC--\n\n";
		$this->assertSame( $sig, ApplePass::smime_to_der( $mime ) );
		$this->assertSame( $sig, ApplePass::smime_to_der( str_replace( "\n", "\r\n", $mime ) ), 'anche con i ritorni a capo di Windows' );
		$this->expectException( \InvalidArgumentException::class );
		ApplePass::smime_to_der( 'niente firma' );
	}

	public function test_pkpass_structure_manifest_and_signature(): void {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'estensione zip non disponibile' );
		}
		list( $cert, $key ) = $this->make_cert( 'Pass Type ID: pass.test.apse' );
		list( $wwdr )       = $this->make_cert( 'Apple Worldwide Developer Relations' );
		$cfg                = array( 'pass_type' => 'pass.test.apse', 'team' => 'TEAM123456', 'cert' => $cert, 'key' => $key, 'wwdr' => $wwdr );
		$bin                = ApplePass::build( $cfg, $this->card() );
		$tmp                = tempnam( sys_get_temp_dir(), 'pk' );
		file_put_contents( $tmp, $bin );
		$zip = new \ZipArchive();
		$this->assertTrue( true === $zip->open( $tmp ) );
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}
		sort( $names );
		$this->assertSame( array( 'icon.png', 'icon@2x.png', 'icon@3x.png', 'manifest.json', 'pass.json', 'signature' ), $names );
		$manifest = json_decode( $zip->getFromName( 'manifest.json' ), true );
		foreach ( array( 'pass.json', 'icon.png', 'icon@2x.png', 'icon@3x.png' ) as $f ) {
			$this->assertSame( sha1( $zip->getFromName( $f ) ), $manifest[ $f ], "hash di $f" );
		}
		$this->assertArrayNotHasKey( 'signature', $manifest );
		$pass = json_decode( $zip->getFromName( 'pass.json' ), true );
		$this->assertSame( 'pass.test.apse', $pass['passTypeIdentifier'] );
		$this->assertSame( 'TEAM123456', $pass['teamIdentifier'] );
		$this->assertSame( 'apse-12', $pass['serialNumber'] );
		$this->assertSame( 'Mario Rossi', $pass['generic']['primaryFields'][0]['value'] );
		$this->assertSame( 'PKBarcodeFormatQR', $pass['barcodes'][0]['format'] );
		$this->assertSame( 'https://example.org/?apse_card=1.abc', $pass['barcodes'][0]['message'] );
		$this->assertSame( '2026-08-31T23:59:59+02:00', $pass['expirationDate'] );
		$der = $zip->getFromName( 'signature' );
		$this->assertSame( "\x30", $der[0], 'firma PKCS#7 in formato DER' );
		// Verifica indipendente con lo strumento openssl (se c'è): la firma staccata deve corrispondere al manifesto
		if ( '' !== trim( (string) shell_exec( 'command -v openssl 2>/dev/null' ) ) ) {
			$m = tempnam( sys_get_temp_dir(), 'pkm' );
			$s = tempnam( sys_get_temp_dir(), 'pks' );
			file_put_contents( $m, $zip->getFromName( 'manifest.json' ) );
			file_put_contents( $s, $der );
			exec( 'openssl smime -verify -noverify -binary -inform DER -in ' . escapeshellarg( $s ) . ' -content ' . escapeshellarg( $m ) . ' -out /dev/null 2>&1', $out, $code );
			$this->assertSame( 0, $code, 'openssl verifica la firma: ' . implode( ' ', $out ) );
			// e una firma su un manifesto diverso non deve passare
			file_put_contents( $m, $zip->getFromName( 'manifest.json' ) . ' ' );
			exec( 'openssl smime -verify -noverify -binary -inform DER -in ' . escapeshellarg( $s ) . ' -content ' . escapeshellarg( $m ) . ' -out /dev/null 2>&1', $out2, $code2 );
			$this->assertNotSame( 0, $code2, 'un manifesto modificato non passa la verifica' );
			unlink( $m );
			unlink( $s );
		}
		$zip->close();
		unlink( $tmp );
	}

	public function test_pkpass_without_qr_has_no_barcode(): void {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'estensione zip non disponibile' );
		}
		list( $cert, $key ) = $this->make_cert( 'Pass Type ID: pass.test.apse' );
		$bin                = ApplePass::build( array( 'pass_type' => 'pass.x', 'team' => 'T', 'cert' => $cert, 'key' => $key, 'wwdr' => $cert ), array_merge( $this->card( '' ), array( 'expires_iso' => null ) ) );
		$tmp                = tempnam( sys_get_temp_dir(), 'pk' );
		file_put_contents( $tmp, $bin );
		$zip  = new \ZipArchive();
		$zip->open( $tmp );
		$pass = json_decode( $zip->getFromName( 'pass.json' ), true );
		$zip->close();
		unlink( $tmp );
		$this->assertArrayNotHasKey( 'barcodes', $pass );
		$this->assertArrayNotHasKey( 'barcode', $pass );
		$this->assertArrayNotHasKey( 'expirationDate', $pass );
	}

	public function test_pkpass_rejects_bad_credentials(): void {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'estensione zip non disponibile' );
		}
		$this->expectException( \InvalidArgumentException::class );
		ApplePass::build( array( 'pass_type' => 'p', 'team' => 't', 'cert' => 'x', 'key' => 'y', 'wwdr' => 'z' ), $this->card() );
	}
}
