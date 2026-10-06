<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Tessera per Apple Wallet (.pkpass): un archivio zip con pass.json, le icone, il manifesto con gli hash e la firma PKCS#7
 * fatta con il certificato "Pass Type ID" dell'associazione (account Apple Developer) e il certificato intermedio WWDR di Apple.
 */
final class ApplePass {

	/**
	 * @param array $cfg  pass_type, team, cert (PEM), key (PEM), wwdr (PEM)
	 * @param array $card serial, name, type, card_number, until_text, expires_iso (o null), org, color (#RRGGBB), url (valore del QR, o ''), description
	 * @return string contenuto del file .pkpass
	 * @throws \InvalidArgumentException
	 */
	public static function build( array $cfg, array $card ): string {
		if ( ! class_exists( '\ZipArchive' ) || ! function_exists( 'openssl_pkcs7_sign' ) ) {
			throw new \InvalidArgumentException( 'Questo server non può creare le tessere per Apple Wallet (servono le estensioni zip e openssl di PHP).' );
		}
		$rgb  = Png::rgb( (string) ( $card['color'] ?? '' ) );
		$fg   = Png::is_light( $rgb ) ? 'rgb(0, 0, 0)' : 'rgb(255, 255, 255)';
		$pass = array(
			'formatVersion'      => 1,
			'passTypeIdentifier' => (string) $cfg['pass_type'],
			'teamIdentifier'     => (string) $cfg['team'],
			'organizationName'   => (string) $card['org'],
			'serialNumber'       => (string) $card['serial'],
			'description'        => (string) ( $card['description'] ?? 'Tessera associativa' ),
			'logoText'           => (string) $card['org'],
			'foregroundColor'    => $fg,
			'labelColor'         => $fg,
			'backgroundColor'    => sprintf( 'rgb(%d, %d, %d)', $rgb[0], $rgb[1], $rgb[2] ),
			'generic'            => array(
				'primaryFields'   => array( array( 'key' => 'name', 'label' => 'SOCIO', 'value' => (string) $card['name'] ) ),
				'secondaryFields' => array(
					array( 'key' => 'card', 'label' => 'TESSERA N.', 'value' => (string) $card['card_number'] ),
					array( 'key' => 'type', 'label' => 'TIPO', 'value' => (string) $card['type'] ),
				),
				'auxiliaryFields' => array( array( 'key' => 'valid', 'label' => 'VALIDA FINO AL', 'value' => (string) $card['until_text'] ) ),
				'backFields'      => array( array( 'key' => 'info', 'label' => 'Informazioni', 'value' => 'La validità della tessera si verifica sempre in diretta: il periodo indicato è quello al momento dell\'emissione.' ) ),
			),
		);
		if ( ! empty( $card['url'] ) ) {
			$code                = array( 'format' => 'PKBarcodeFormatQR', 'message' => (string) $card['url'], 'messageEncoding' => 'iso-8859-1' );
			$pass['barcodes']    = array( $code );
			$pass['barcode']     = $code; // iOS precedenti alla 9
		}
		if ( ! empty( $card['expires_iso'] ) ) {
			$pass['expirationDate'] = (string) $card['expires_iso'];
		}
		$files = array(
			'pass.json'    => (string) json_encode( $pass, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'icon.png'     => Png::solid( 29, 29, $rgb[0], $rgb[1], $rgb[2] ),
			'icon@2x.png'  => Png::solid( 58, 58, $rgb[0], $rgb[1], $rgb[2] ),
			'icon@3x.png'  => Png::solid( 87, 87, $rgb[0], $rgb[1], $rgb[2] ),
		);
		$manifest = array();
		foreach ( $files as $name => $bytes ) {
			$manifest[ $name ] = sha1( $bytes );
		}
		$manifest_json = (string) json_encode( $manifest, JSON_UNESCAPED_SLASHES );
		$signature     = self::sign( $manifest_json, $cfg );

		$tmp = (string) tempnam( sys_get_temp_dir(), 'apsepk' );
		try {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				throw new \InvalidArgumentException( 'Impossibile creare il file della tessera.' );
			}
			foreach ( $files as $name => $bytes ) {
				$zip->addFromString( $name, $bytes );
			}
			$zip->addFromString( 'manifest.json', $manifest_json );
			$zip->addFromString( 'signature', $signature );
			$zip->close();
			return (string) file_get_contents( $tmp );
		} finally {
			@unlink( $tmp );
		}
	}

	/** Firma staccata (PKCS#7, DER) del manifesto. */
	public static function sign( string $manifest_json, array $cfg ): string {
		$cert = @openssl_x509_read( (string) $cfg['cert'] );
		$key  = @openssl_pkey_get_private( (string) $cfg['key'] );
		if ( ! $cert || ! $key ) {
			throw new \InvalidArgumentException( 'Certificato o chiave per Apple Wallet non validi: ricaricali dalla pagina "Tessera e Wallet".' );
		}
		$in   = (string) tempnam( sys_get_temp_dir(), 'apsem' );
		$out  = (string) tempnam( sys_get_temp_dir(), 'apseo' );
		$wwdr = (string) tempnam( sys_get_temp_dir(), 'apsew' );
		try {
			file_put_contents( $in, $manifest_json );
			file_put_contents( $wwdr, (string) $cfg['wwdr'] );
			if ( ! openssl_pkcs7_sign( $in, $out, $cert, $key, array(), PKCS7_BINARY | PKCS7_DETACHED, $wwdr ) ) {
				throw new \InvalidArgumentException( 'Firma della tessera non riuscita: controlla il certificato e il certificato intermedio WWDR.' );
			}
			return self::smime_to_der( (string) file_get_contents( $out ) );
		} finally {
			@unlink( $in );
			@unlink( $out );
			@unlink( $wwdr );
		}
	}

	/** Estrae la firma (base64) dal messaggio S/MIME prodotto da OpenSSL e la restituisce in binario. */
	public static function smime_to_der( string $smime ): string {
		$s = str_replace( "\r\n", "\n", $smime );
		if ( ! preg_match( '/filename="smime\.p7s"\n\n(.+?)\n+------/s', $s, $m ) ) {
			throw new \InvalidArgumentException( 'Firma della tessera non leggibile.' );
		}
		$der = base64_decode( $m[1], false );
		if ( false === $der || '' === $der ) {
			throw new \InvalidArgumentException( 'Firma della tessera non leggibile.' );
		}
		return $der;
	}
}
